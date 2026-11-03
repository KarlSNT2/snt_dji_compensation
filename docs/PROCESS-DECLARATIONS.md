# Process global — Déclarations DJI Compensation

De la soumission par le revendeur jusqu'à l'archivage mensuel du récap.

---

## 1. Vue d'ensemble

```
Revendeur (front PrestaShop)
   │  dépose un XLSX + factures PDF (+ photos stock si Mic/Power)
   ▼
Module snt_dji_compensation  ──────────────────────  MySQL PrestaShop
   │  déclaration en base, fichiers sur S3                (métadonnées)
   │  status: draft → submitted
   ▼
API  /api?action=declarations-summary&status=submitted
   │  lit les XLSX sur S3, les parse, renvoie les lignes
   ▼
n8n « Declarations summary »  (cron 5 min)
   │  enrichit via promotions_dji, détecte RETARD + DOUBLON
   ▼
Postgres APP Metier — table declarations_export  ◄── SOURCE DE VÉRITÉ
   │
   ├──► Google Sheet « DJI Compensations » / Feuille 1   (récap de travail)
   │
   └──► API /api?action=update-status&status=processing  (boucle de retour)

                    puis, sur `processing`, le pipeline des numeros de serie
                    prend le relais — voir PROCESS-DECLARATIONS-SN.md

n8n « Archive Declarations »  (cron le 8 du mois)
   │  déplace les lignes des promos terminées
   ▼
Onglets « Mois Année - DJI Compensations » du même classeur
```

**Principe directeur :** `declarations_export` est la source de vérité. Le Google
Sheet n'en est qu'un rendu, régénérable à tout moment. C'est ce qui autorise le
clear/rewrite de l'archivage et rend le récap auto-réparant.

---

## 2. Côté module PrestaShop

### 2.1 Dépôt de la déclaration

Front-office, contrôleur `controllers/front/declaration.php`.

Le revendeur téléverse un **XLSX** (obligatoire), des **factures PDF** et, pour
les gammes Mic & Power, des **photos de stock**. Tous les fichiers partent sur S3 ;
la base MySQL ne garde que les métadonnées (`dji_compensation_declaration`,
`dji_compensation_invoice`, `dji_compensation_stock_proof`, `dji_compensation_history`).

**Le XLSX n'est pas parsé à la soumission.** Il est stocké tel quel. C'est l'API
qui le lit plus tard, à la demande.

### 2.2 Cycle de vie du statut

| Statut       | Sens                                    |
|--------------|-----------------------------------------|
| `draft`      | Brouillon, modifiable                   |
| `submitted`  | Soumise — **c'est ce que n8n consomme** |
| `processing` | Promotions exportées, SN en attente     |
| `registered` | Les deux exports sont faits             |
| `validated`  | Validée                                 |
| `rejected`   | Rejetée                                 |

Point structurant : `isXlsxEditable()` n'autorise le remplacement du XLSX
**qu'en brouillon** (`classes/DJICompensationDeclaration.php`). Dès la
soumission, les lignes sont figées définitivement. C'est ce qui permet d'utiliser
la position de la ligne dans le fichier comme clé d'idempotence (voir §4.2).

### 2.3 Règles métier actées

- **1 déclaration par promotion et par revendeur.** Une seconde déclaration sur
  la même promo est un doublon.
- **7 jours pour déclarer** après la fin d'une promo. Au-delà, pas de compensation.
- **Aucun blocage à la soumission.** Un doublon ou un retard est *signalé*, jamais
  refusé — décision commerciale, pour ne pas frustrer les revendeurs. L'équipe
  traite les cas signalés.

### 2.4 L'API consommée par n8n

`controllers/front/api.php`, authentification par Bearer token.

| Action                   | Rôle                                                    |
|--------------------------|---------------------------------------------------------|
| `declarations-summary`   | Lit les XLSX sur S3, les parse, renvoie toutes les lignes |
| `update-status`          | Fait passer une déclaration à un nouveau statut          |

`declarations-summary` accepte `?status=` parmi `submitted`, `processing`,
`validated`, `registered`. Les trois premiers sont les statuts par défaut quand
aucun filtre n'est passé ; `registered` n'est interrogeable qu'explicitement, pour
permettre de rejouer l'export de déclarations déjà traitées sans changer le
comportement du flux nominal.

---

## 3. Le référentiel des promotions

Base **Postgres « APP Metier »**, table `promotions_dji`
(`scripts-sql/01_create_table_promotions_dji.sql`).

Elle porte le type de promo, les dates officielles de début et de fin, la
compensation HT. Elle est alimentée par un flux séparé depuis le fichier Excel
DJI.

**Conséquence d'architecture :** ce référentiel n'est pas dans MySQL PrestaShop.
Toute jointure promo doit donc se faire côté Postgres — c'est la raison pour
laquelle `declarations_export` vit dans Postgres et non dans le module.

> **Piège connu.** Les colonnes `DATE` de Postgres sont converties par le driver
> en objets `Date` JavaScript à minuit heure locale, puis sérialisées en UTC par
> n8n. En été à Paris, `2026-08-31` devenait `2026-08-30T22:00:00Z`, soit un jour
> de perdu après troncature. Toutes les requêtes du flux renvoient donc les dates
> via `to_char(..., 'YYYY-MM-DD')`, en texte.

---

## 4. Flux n8n « Declarations summary » — l'export

Cron **toutes les 5 minutes**.

### 4.1 Chaîne des nodes

```
Schedule → IS DEV → IF PROD → PROD / DEV → Pass
   → GET Promotions Types      (Postgres, référentiel promos, to_char sur les dates)
   → Limit 1                   (fan-in : une seule exécution du HTTP)
   → GET Declarations Summary  (API, status=submitted&limit=20)
        ├─1→ Split Out                 (éclate le tableau lines)
        │    → Map to Google Sheets Columns   (Code — enrichissement)
        │    → Insert Export Lines            (Postgres — insertion + anomalies)
        │    → Format for Sheet               (Code — mise en forme)
        │    → Google Sheets - Append         (Feuille 1)
        └─2→ Collect Declaration IDs   (Code — processed_declarations)
             → Mark as Processing      (API, status=processing)
```

`processing` est le signal d'entrée du second pipeline, celui des numéros de
série, qui posera `registered` à son tour. Remettre `registered` ici le priverait
définitivement de matière — voir `PROCESS-DECLARATIONS-SN.md` §2.

### 4.2 `Map to Google Sheets Columns`

Pour chaque ligne déclarée :

1. **Cherche la bonne promo** dans `promotions_dji`, par référence article, avec
   trois stratégies en cascade : date de début identique → période contenant la
   date → promo unique pour cette référence.
2. **Préfère les dates officielles** de la promo à celles saisies par le revendeur.
   Un écart entre la valeur de l'API et celle du Sheet est donc normal et voulu.
3. **Construit `NOM DU CLIENT`** comme `Société - Nom`, en tolérant l'absence de
   l'un ou l'autre.
4. **Attribue un `ligne_num`** : la position de la ligne dans son XLSX. Le fichier
   étant figé depuis la soumission, ce numéro est stable d'un run à l'autre —
   c'est la clé d'idempotence.

Le node n'émet que des valeurs brutes (dates ISO, nombres). Aucun statut n'y est
calculé.

### 4.3 `Insert Export Lines` — le cœur

Une seule requête paramétrée qui insère la ligne **et** détermine ses anomalies :

- **DOUBLON** — un `LEFT JOIN LATERAL` cherche une ligne antérieure ayant le même
  triplet `(id_customer, ref_article, date_fin)`, sur une *autre* déclaration.
  Si trouvée, la ligne est marquée et on conserve la référence de la déclaration
  d'origine.
- **RETARD** — `date_declaration > date_fin + 7 jours`.
- **Idempotence** — `ON CONFLICT (id_declaration, ligne_num) DO NOTHING`. Rejouer
  le flux n'insère rien et, le `RETURNING` étant vide, n'ajoute rien au Sheet.

Deux réglages sont indispensables sur ce node :

| Réglage | Valeur | Pourquoi |
|---|---|---|
| **Query Batching** | `Independently` | Chaque ligne voit celles insérées avant elle dans le même run → les doublons *intra-lot* sont détectés |
| **Query Parameters** | une seule expression renvoyant un **tableau** | n8n résout sinon en chaîne puis découpe sur les virgules : une valeur vide disparaît, une virgule dans un nom de client crée un paramètre fantôme |

```
{{ [$json.id_declaration, $json.ligne_num, ... , $json.date_declaration] }}
```

Deux choix assumés :

- **Deux lignes de la même déclaration sur la même promo ne sont pas un doublon.**
  La règle est « 1 déclaration par promo » ; un revendeur qui découpe sa promo sur
  plusieurs lignes de son fichier (numéros de série, factures distinctes) reste
  dans une seule déclaration.
- **Le node est en échec bloquant.** Une ligne malformée arrête le run plutôt que
  d'être perdue silencieusement — les équipes se fient au récap.

### 4.4 `Format for Sheet`

Compose le `STATUT` affiché, cumulatif : `RETARD`, `DOUBLON`, ou `RETARD + DOUBLON`.
Alimente `DOUBLON DE` avec la référence et la date de la déclaration d'origine,
directement actionnable pour la relance commerciale.

Toutes les dates sont déjà formatées par `to_char()` côté SQL : aucune conversion
`Date` JavaScript, donc aucun décalage de fuseau possible.

Un filtre `typeof i.json.is_doublon === 'boolean'` écarte l'item placeholder que
le node Postgres peut émettre quand rien n'a été inséré — sans lui, un rejeu
ajouterait une ligne vide au récap.

### 4.5 Marquage et traitement par lots (septembre 2026)

**Le marquage ne dépend plus de l'export**, comme dans le flux SN : une seconde
branche, alimentée par `processed_declarations` (déclarations lues sans erreur),
fait passer en `processing` toutes les déclarations examinées, qu'elles aient
produit des lignes ou non. Avant, une déclaration sans quantité sur la feuille 1,
ou dont le marquage avait échoué après un export réussi (l'insert ne renvoyant
plus rien au rejeu), restait bloquée en `submitted` et n'atteignait jamais le
flux SN. L'export reste en première branche : s'il échoue, le run s'arrête avant
le marquage et tout est retenté au passage suivant.

**Lots.** `declarations-summary` et `declarations-sn-summary` acceptent
`?limit=N` (plafonné à 200) : les N déclarations les plus anciennes sont
traitées, les suivantes aux runs suivants. La réponse porte
`remaining_declarations`. Les flux appellent avec `limit=20` : après une
interruption (vacances, panne), le stock se résorbe à raison de 20 déclarations
toutes les 5 minutes, sans risquer `max_execution_time` (porté à 300 s par
l'endpoint) ni le timeout du node HTTP (300 s).

Une déclaration en erreur permanente (fichier S3 absent) reste en tête de file et
occupe une place du lot à chaque run : la corriger ou la rejeter à la main.

---

## 5. La table `declarations_export`

`scripts-sql/05_create_table_declarations_export.sql`

Miroir exact du Google Sheet, plus ce que le Sheet ne peut pas porter.

| Groupe | Colonnes |
|---|---|
| Traçabilité | `id_declaration`, `ligne_num`, `id_customer`, `declaration_reference` |
| Miroir du Sheet | `nom_du_client`, `type_de_promo`, `ref_article`, `designation`, `date_debut`, `date_fin`, `quantite`, `compensation_ht`, `total`, `ref_avoir`, `date_declaration` |
| Anomalies | `is_retard`, `is_doublon`, `doublon_de_declaration`, `doublon_de_reference`, `doublon_de_date` |
| Suivi commercial | `traite`, `traite_par`, `traite_at`, `commentaire` |
| Archivage | `archived_at`, `archive_period`, `archive_sheet_name`, `periode` |

Index notables :

- `uq_declarations_export_ligne` — **UNIQUE** sur `(id_declaration, ligne_num)`.
  Sans lui, `ON CONFLICT` échoue avec *« no unique or exclusion constraint
  matching the ON CONFLICT specification »*.
- `idx_declarations_export_promo` sur `(id_customer, ref_article, date_fin)` —
  le lookup de détection de doublon.
- `idx_declarations_export_a_archiver` sur `(periode) WHERE archived_at IS NULL`.

### La colonne générée `periode`

```sql
date_trunc('month', COALESCE(date_fin, date_declaration::date)::timestamp)::date
```

Mois de rattachement pour l'archivage : le mois de `date_fin`, ou à défaut le
mois de déclaration pour les baisses de prix définitives sans date de fin.

> **Le cast `::timestamp` est obligatoire.** Sans lui, `date_trunc(text, date)` est
> résolu vers la surcharge `timestamptz`, qui est `STABLE` et non `IMMUTABLE` :
> Postgres refuse alors la colonne générée (`SQLSTATE 42P17`).

---

## 6. Le Google Sheet de récap

Classeur **« DJI Compensations »**, onglet **Feuille 1**. 14 colonnes, dans cet ordre :

```
REF DECLARATION | NOM DU CLIENT | TYPE DE PROMO | REF ARTICLE | DESIGNATION
DATE DEBUT | DATE FIN | QUANTITE | COMPENSATION HT | TOTAL | REF AVOIR
STATUT | DOUBLON DE | DATE DECLARATION
```

Le mapping n8n se fait **par nom d'en-tête**. Une colonne absente de la ligne 1
fait disparaître silencieusement la donnée — c'est exactement ce qui arrivait à
`DESIGNATION` avant correction.

Le récap est **en lecture seule** pour les équipes. C'est cette hypothèse qui
autorise le clear/rewrite de l'archivage. Si elle change, l'archivage est à
revoir : leurs annotations seraient écrasées. Les colonnes `traite`, `traite_par`
et `commentaire` de la base sont prévues pour accueillir ce suivi le jour venu.

---

## 7. Flux n8n « Archive Declarations » — l'archivage mensuel

Cron `0 6 8 * *` — **le 8 de chaque mois à 6h**, soit juste après la clôture du
délai de déclaration de 7 jours pour les promos finies en fin de mois précédent.

### 7.1 Chaîne des nodes

```
Schedule (8 du mois)
   → Get Periods To Archive    (Postgres — mois à traiter + nom de l'onglet)
   → Loop Periods              (batch = 1)
        ├─ Create Archive Tab     (Sheets, On Error = Continue)
        ├─ Get Lines For Period   (Postgres)
        ├─ Format Archive Rows    (Code)
        ├─ Append To Archive Tab  (Sheets)
        └─ Mark Archived          (Postgres)
   → (done) Get Remaining Lines   (Postgres, Always Output Data)
   → Clear Recap                  (Sheets, Keep First Row)
   → Format Recap Rows            (Code)
   → Rewrite Recap                (Sheets, append)
```

### 7.2 Sélection des périodes

La requête ne demande pas « le mois précédent » mais **tout ce qui n'est pas
archivé et dont la promo est terminée avant le mois courant** :

```sql
WHERE archived_at IS NULL
  AND periode IS NOT NULL
  AND periode < date_trunc('month', CURRENT_DATE)::date
```

Même complexité, mais aucune ligne ne peut rester orpheline dans le récap — y
compris une déclaration hors délai qui, bien que non compensée, existe en base.

Le nom de l'onglet est composé en SQL par un `CASE` sur le numéro de mois plutôt
que par `to_char(..., 'TMMonth')` : aucune dépendance à la locale du serveur.

### 7.3 `Create Archive Tab` doit tolérer l'échec

L'onglet peut déjà exister — rejeu du flux, ou ligne d'un mois déjà archivé
arrivée après coup. Le node est donc en **On Error → Continue**.

### 7.4 Pourquoi clear + rewrite plutôt qu'une suppression de lignes

Google Sheets n'offre pas de « supprimer les lignes qui matchent ». Le node
n8n prend un index de départ et un nombre de lignes. Il faudrait relire la
feuille, retrouver les index, les trier en ordre décroissant pour éviter le
décalage, et enchaîner N appels — sachant que les lignes d'un mois ne sont même
pas contiguës. Un échec en cours de route laisse la feuille à moitié traitée.

Le clear/rewrite fait **2 appels**, est déterministe, et donne par construction
un résultat identique à la base.

Ce que le *Clear* **ne détruit pas** : formatage des cellules, mise en forme
conditionnelle, filtres et vues filtrées, lignes figées, et la ligne d'en-tête
grâce à l'option *Keep First Row*.

### 7.5 L'ordre des nodes est la sécurité

`Get Remaining Lines` s'exécute **avant** `Clear Recap`. Si la base est
injoignable, le flux s'arrête et la feuille n'est jamais vidée. On ne peut pas
se retrouver avec un récap effacé et rien pour le remplir.

Effet de bord bénéfique : la réécriture mensuelle rend le récap **auto-réparant**.
Toute dérive entre la base et le Sheet est corrigée au run suivant.

### 7.6 Limite connue

Entre le `Clear` et le `Rewrite`, quelques secondes une fois par mois, le cron
d'export (5 min) peut insérer une ligne. Elle serait en base mais absente du
Sheet jusqu'à l'archivage suivant. Fermeture possible : désactiver le workflow
d'export au début du flux et le réactiver à la fin, via deux appels à l'API n8n.
Non implémenté.

---

## 8. Mise en service — ordre des opérations

1. **Postgres** — exécuter dans CloudBeaver, avec **Alt+X** (*Execute script*) et
   non Ctrl+Entrée qui n'exécute que l'instruction sous le curseur :
   - `scripts-sql/05_create_table_declarations_export.sql`
   - `scripts-sql/06_add_archive_columns.sql`

   Contrôle : 8 lignes attendues (7 index + la clé primaire), dont
   `uq_declarations_export_ligne`.

   ```sql
   SELECT indexname FROM pg_indexes
   WHERE tablename = 'declarations_export' ORDER BY indexname;
   ```

2. **Google Sheet** — poser les 14 en-têtes en ligne 1 de Feuille 1.

3. **n8n « Declarations summary »** — appliquer les modifications, puis activer
   le cron 5 minutes.

4. **n8n « Archive Declarations »** — monter le flux, le tester **en manuel**
   avant d'activer le cron.

### Recette

**Export et doublon** — soumettre deux déclarations pour le même client, la même
référence article et la même date de fin. La seconde doit ressortir avec
`STATUT = DOUBLON` et la référence de la première dans `DOUBLON DE`.

**Idempotence** — relancer le flux d'export sans rien changer : **rien** ne doit
être ajouté, ni en base ni dans le Sheet.

**Archivage** — avant le run, visualiser ce qui sera traité :

```sql
SELECT periode, count(*) FROM public.declarations_export
WHERE archived_at IS NULL GROUP BY periode ORDER BY periode;
```

Après le run : les lignes de la période ont un `archived_at` renseigné, l'onglet
du mois existe et les contient, Feuille 1 ne contient plus que le reste.

---

## 9. Pièges rencontrés — à ne pas réintroduire

| Symptôme | Cause | Correctif |
|---|---|---|
| `syntax error at or near "exported_at"` | CloudBeaver coupe les instructions sur les **lignes vides** (*Blank line is statement delimiter*) | Aucune ligne vide à l'intérieur d'une instruction ; exécuter avec Alt+X |
| `Variable $15 out of range. Parameters array length: 14` | n8n résout *Query Parameters* en chaîne puis découpe sur les virgules ; une valeur vide disparaît | Une seule expression renvoyant un **tableau** : `{{ [ ... ] }}` |
| `no unique or exclusion constraint matching the ON CONFLICT` | Index `uq_declarations_export_ligne` non créé | Exécuter le bloc des index |
| `generation expression is not immutable` (42P17) | `date_trunc(text, date)` résolu vers la surcharge `timestamptz`, qui est `STABLE` | Cast explicite `::timestamp` |
| Date de fin décalée d'un jour | `DATE` Postgres → objet `Date` JS (minuit local) → sérialisation UTC | `to_char(..., 'YYYY-MM-DD')` dans toutes les requêtes |
| Opérateur `<>` disparu au copier-coller | Rendu HTML qui avale les chevrons | `IS DISTINCT FROM` |
| `DESIGNATION` absente du récap | Champ émis par le code mais pas dans les en-têtes du Sheet ; `autoMapInputData` le jette en silence | Ajouter la colonne en ligne 1 |
| `null - Jean Dupont` dans `NOM DU CLIENT` | Précédence des opérateurs : `a + " - " + b \|\| b \|\| ''` rend les fallbacks morts | Filtrer puis joindre |

---

## 10. Fichiers de référence

| Chemin | Contenu |
|---|---|
| `controllers/front/api.php` | API consommée par n8n |
| `controllers/front/declaration.php` | Dépôt de la déclaration côté revendeur |
| `classes/DJICompensationDeclaration.php` | Statuts, règles d'éditabilité |
| `scripts-sql/01_create_table_promotions_dji.sql` | Référentiel des promos |
| `scripts-sql/05_create_table_declarations_export.sql` | Table miroir du récap |
| `scripts-sql/06_add_archive_columns.sql` | Colonnes d'archivage + `periode` |
| `scripts-sql/tests/test_declarations_export.py` | 10 tests de la règle de doublon |
| `n8n_workflows/Declarations summary (1).json` | Flux d'export |
| `docs/PROCESS-DECLARATIONS-SN.md` | Pipeline jumeau, sur la feuille des numéros de série |

### Tester la règle de doublon

Le harnais lit la requête **directement dans le workflow**, il n'y a donc pas de
duplication : modifier le node et relancer suffit à vérifier la règle.

```bash
pip install "psycopg[binary]"
docker run -d --name djitest -e POSTGRES_PASSWORD=test -e POSTGRES_DB=app_db \
    -p 55432:5432 postgres:16-alpine
python scripts-sql/tests/test_declarations_export.py
```
