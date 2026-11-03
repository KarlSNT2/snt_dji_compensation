# Process — Déclarations de numéros de série (SN)

Second pipeline, jumeau de celui décrit dans `PROCESS-DECLARATIONS.md`, mais sur
la **feuille 2** du fichier client : « Matrice déclaration SN ».

À lire après le document principal : seules les différences sont détaillées ici.

---

## 1. Pourquoi un second pipeline

Le fichier déposé par le revendeur porte deux déclarations de nature différente :

| Feuille | Unité de déclaration | Pipeline |
|---|---|---|
| `Promotions et Baisses de prix` | une quantité par promotion | `PROCESS-DECLARATIONS.md` |
| `Matrice déclaration SN` | **un numéro de série** | ce document |

La feuille SN couvre deux situations imposées par DJI : les promotions sur les
gammes **Mic** et **Power** (produits sans activation), et les **baisses de prix /
protection de stock** sur tout produit non encore activé. Dans les deux cas DJI
compense **au numéro de série**, et refuse la compensation en cas d'erreur de
déclaration.

Les deux exports sont indépendants : une déclaration peut alimenter les deux, un
seul, ou aucun.

---

## 2. Le chaînage des statuts

C'est le point qui relie les deux pipelines, et la seule modification apportée au
flux existant.

```
submitted
   │
   ▼  « Declarations summary »       (cron 5 min)  → GSheet Promotions
processing
   │
   ▼  « Declarations SN summary »    (cron 5 min)  → GSheet SN
registered
```

Le flux promotions pose désormais `processing` au lieu de `registered`. Le flux
SN prend le relais sur `processing` et pose `registered`.

**Pourquoi ce chaînage plutôt que deux flux parallèles.** Les deux flux
consomment les mêmes déclarations. S'ils lisaient tous deux `submitted`, le
premier à passer les ferait basculer et priverait l'autre de sa matière. Le
chaînage donne un ordre déterministe sans coupler les flux, et garantit que
chaque déclaration n'est téléchargée et parsée qu'une seule fois par pipeline.

> **Ne pas remettre `registered` dans le flux promotions.** Le flux SN ne verrait
> plus jamais aucune déclaration. Une note collante le rappelle dans n8n.

**Conséquence sur le vocabulaire :** `processing` ne signifie plus « en cours de
traitement » au sens large, mais précisément « promotions exportées, SN en
attente ». Le libellé client reste « En traitement ».

---

## 3. Lecture de la feuille SN

`controllers/front/api.php`, action `declarations-sn-summary`.

### 3.1 Ni le nom de la feuille ni la ligne d'en-têtes ne sont figés

Le nom a changé quatre fois selon les versions du template — « Matrice Baisses de
prix », « Numéros SN », « Matrice déclaration SN », puis « Numéros de Série (SN)
déclarés » (septembre 2026) — et un client peut déposer un
fichier téléchargé il y a plusieurs mois. Les en-têtes, eux, sont précédés d'un
bloc de consignes : ils sont en ligne 3 dans la version actuelle, en ligne 2 ou 1
dans les précédentes.

Plutôt que de figer un nom et un numéro de ligne, on les reconnaît à leur
contenu (`DJICompensationXlsxReader`) :

- `findSheetByAliases()` — la feuille dont le nom contient l'un des fragments
  connus, comparés sans casse ni accents ;
- `findHeaderRow()` — parmi les 15 premières lignes, celle qui contient le plus
  de libellés attendus (`numero sn`, `reference produit`, `reference article`,
  `produit`, `quantite`, `compensation`, `date de debut`), avec un minimum de 3.

Depuis septembre 2026, les en-têtes de la feuille SN reprennent ceux de la
feuille 1 (« Référence produit - STS », « Prix Promo TTC », « Baisse de prix »,
« Compensation €HT »…). `getSnColumnAliases()` accepte les deux générations.

Vérifié sur les neuf templates de `uploads/templates/` : détection correcte sur
les six qui portent une feuille SN, et absence proprement signalée sur les trois
autres.

### 3.2 Ce qui fait une ligne

**Le numéro de série.** Une ligne sans SN est ignorée. Ce seul critère écarte du
même coup :

- les lignes de saisie vides, qui portent malgré tout une formule `=J*K` dont le
  résultat mis en cache vaut `0` ;
- la ligne **TOTAL** en pied de tableau.

La quantité vaut `1` par défaut — c'est la règle du template, « 1 ligne par
numéro de série et produit ».

> **La colonne A « Type d'opération »** (intitulée « Revendeur » avant
> septembre 2026, les deux sont reconnus) porte le type saisi par le revendeur
> (« Promotion avec numéro de série »). On remonte la valeur brute
> sous le nom `type_declare` sans lui prêter de sens ; le type de promo affiché
> vient du référentiel `promotions_dji`, comme pour la feuille 1.

### 3.3 La réponse

Même forme que `declarations-summary`, avec trois champs en plus :

| Champ | Rôle |
|---|---|
| `processed_declarations` | ids des déclarations **réellement examinées**, y compris celles sans ligne SN |
| `declarations_without_sn_sheet` | ids dont le fichier ne porte aucune feuille SN reconnaissable |
| `errors` | échecs S3 ou parsing ; ces déclarations ne sont **pas** dans `processed_declarations` |

`processed_declarations` est le champ structurant — voir §4.2.

---

## 4. Flux n8n « Declarations SN summary »

Cron **toutes les 5 minutes**. `n8n_workflows/Declarations SN summary.json`

### 4.1 Chaîne des nodes

```
Schedule / Manual → IS DEV → IF PROD → PROD / DEV → Pass
   → GET Promotions Types      (Postgres, référentiel promos, to_char sur les dates)
   → Limit 1                   (fan-in : une seule exécution du HTTP)
   → GET Declarations SN Summary  (API, status=processing)
        ├─1→ Split Out → Map to SN Sheet Columns → Insert SN Export Lines
        │       → Format SN for Sheet → Google Sheets Append SN
        └─2→ Collect Declaration IDs → Mark as Registered
```

### 4.2 Les deux branches, et pourquoi

**Le marquage ne dépend pas de l'export.** C'est la différence de conception avec
le flux promotions, et elle est délibérée.

Sur la feuille 1, toute déclaration produit au moins une ligne. Sur la feuille
SN, **la plupart n'en produisent aucune** : seules les gammes Mic et Power et les
protections de stock sont concernées. Si le marquage était branché derrière
`Google Sheets Append` comme dans le flux promotions, une déclaration sans SN
n'atteindrait jamais le node de marquage — aucun item ne circulerait — et
resterait indéfiniment en `processing`, retéléchargée toutes les 5 minutes.

D'où la seconde branche, alimentée par `processed_declarations`, qui fait avancer
toutes les déclarations examinées quelle que soit leur récolte.

**L'ordre des deux branches compte.** Avec `executionOrder: v1`, n8n les parcourt
dans l'ordre déclaré dans `connections`. L'export est en première position : il
est terminé avant que le marquage ne parte. Ne pas réordonner.

**Les déclarations en erreur ne sont pas marquées** : absentes de
`processed_declarations`, elles restent en `processing` et seront retentées au
passage suivant. C'est voulu — une erreur S3 est presque toujours transitoire.

### 4.3 `Insert SN Export Lines` — la règle de doublon

Elle **diffère de celle du pipeline promotions**, et c'est le point à retenir.

DJI compense au numéro de série. Un même SN déclaré deux fois est une double
demande de compensation. Trois cas, tous fautifs, tous à signaler :

1. deux fois dans **le même fichier** — erreur de saisie ;
2. deux fois par **le même revendeur**, sur deux déclarations — double demande ;
3. par **deux revendeurs différents** — l'un des deux se trompe, ou le produit a
   été acheté ailleurs.

La recherche ne se restreint donc **ni au client ni à la déclaration** : elle
exclut seulement la ligne courante elle-même,
`(e.id_declaration, e.ligne_num) IS DISTINCT FROM (v.id_declaration, v.ligne_num)`.

C'est l'inverse du choix fait sur la feuille 1, où deux lignes d'une même
déclaration sur la même promo ne sont pas un doublon.

La colonne `doublon_de_client` porte le nom du revendeur d'origine : sans elle,
la relance commerciale part sur la mauvaise piste dans le cas 3.

**Comparaison insensible à la casse et aux espaces.** Les revendeurs saisissent à
la main. La colonne générée `numero_serie_norm` (`upper(btrim(...))`) sert à la
détection ; `numero_serie` garde la saisie brute pour le Sheet.

Mêmes réglages indispensables que sur le flux promotions :

| Réglage | Valeur | Pourquoi |
|---|---|---|
| **Query Batching** | `Independently` | chaque ligne voit celles insérées avant elle → les doublons *intra-fichier* sont détectés |
| **Query Parameters** | une seule expression renvoyant un **tableau** | sinon n8n découpe sur les virgules (voir §9 du document principal) |

Idempotence identique : `ON CONFLICT (id_declaration, ligne_num) DO NOTHING`.

---

## 5. La table `declarations_sn_export`

`scripts-sql/07_create_table_declarations_sn_export.sql`

Même structure que `declarations_export`, avec :

| Ajout | Rôle |
|---|---|
| `numero_serie` | saisie brute du revendeur, affichée dans le Sheet |
| `numero_serie_norm` | colonne générée `upper(btrim(numero_serie))`, support de la détection de doublon |
| `ean` | remonté par la feuille SN, absent du récap promotions |
| `prix_vente_ttc`, `nouveau_prix_ttc` | hors Sheet, gardés pour les contrôles de prix |
| `doublon_de_client` | nom du revendeur ayant déclaré le SN en premier |

Les colonnes `periode`, `archived_at`, `archive_period`, `archive_sheet_name` et
le bloc de suivi commercial sont identiques à la table promotions.

> Mêmes deux pièges qu'en §5 du document principal : le cast `::timestamp` dans
> `date_trunc` (sans quoi Postgres rejette la colonne générée, SQLSTATE 42P17),
> et l'index unique `uq_declarations_sn_export_ligne` sans lequel `ON CONFLICT`
> échoue.

### Tester la règle de doublon

Même harnais que pour la table promotions ; la requête est lue directement dans
le node du workflow, il n'y a donc pas de duplication.

```bash
pip install "psycopg[binary]"
docker run -d --name djitest -e POSTGRES_PASSWORD=test -e POSTGRES_DB=app_db \
    -p 55432:5432 postgres:16-alpine
python scripts-sql/tests/test_declarations_sn_export.py
```

14 tests : doublons intra-fichier, inter-déclarations, inter-revendeurs,
insensibilité casse/espaces, retard aux bornes, idempotence, période de
rattachement, et virgule dans le nom du client.

---

## 6. Le Google Sheet SN

Classeur **distinct** de celui des promotions, onglet **Feuille 1**. 15 colonnes,
dans cet ordre :

```
REF DECLARATION | NOM DU CLIENT | TYPE DE PROMO | REF ARTICLE | DESIGNATION
EAN | NUMERO SN | DATE DEBUT | DATE FIN | QUANTITE | COMPENSATION HT | TOTAL
STATUT | DOUBLON DE | DATE DECLARATION
```

`DOUBLON DE` est composée de `référence - client - date` de la déclaration
d'origine, là où le récap promotions n'affiche que référence et date.

Le mapping n8n se fait **par nom d'en-tête** : une colonne absente de la ligne 1
fait disparaître silencieusement la donnée.

---

## 7. Flux n8n « Archive Declarations SN »

Cron `0 6 8 * *`, onglets nommés `Mois Année - SN DJI`.

Copie conforme du flux d'archivage promotions, y compris ses deux garde-fous :

- `Get Remaining Lines` s'exécute **avant** `Clear Recap` — si la base est
  injoignable, la feuille n'est jamais vidée ;
- `Create Archive Tab` est en **On Error → Continue** — l'onglet peut déjà
  exister.

Voir §7 du document principal pour le raisonnement complet (clear/rewrite plutôt
que suppression de lignes, effet auto-réparant, limite de la fenêtre entre Clear
et Rewrite).

---

## 8. Mise en service

1. **Postgres** — exécuter `scripts-sql/07_create_table_declarations_sn_export.sql`
   dans CloudBeaver avec **Alt+X** (*Execute script*), pas Ctrl+Entrée.

   Contrôle :

   ```sql
   SELECT indexname FROM pg_indexes
   WHERE tablename = 'declarations_sn_export' ORDER BY indexname;
   ```

   8 lignes attendues (7 index + la clé primaire), dont
   `uq_declarations_sn_export_ligne`.

2. **Google Sheets** — créer le classeur SN, poser les 15 en-têtes en ligne 1.

3. **n8n** — importer les deux workflows, puis dans chacun remplacer le
   `documentId` `REMPLACER_PAR_ID_DU_CLASSEUR_SN` et sélectionner la feuille.
   Les credentials (`APP Metier Postgres`, `Module API Token`,
   `Google Sheets account`) sont déjà référencés par leur id.

4. **Flux promotions** — vérifier que `Mark as Processing` pose bien
   `status=processing`. Sans cela le flux SN ne verra jamais rien.

5. Activer le cron SN **après** avoir testé en manuel.

### Recette

**Export** — soumettre une déclaration dont la feuille SN porte 2 lignes.
Après le passage du flux promotions puis du flux SN : 2 lignes dans le classeur
SN, déclaration en `registered`.

**Déclaration sans SN** — soumettre une déclaration dont la feuille SN est vide.
Rien dans le classeur SN, mais la déclaration doit quand même passer en
`registered`. C'est le cas qui valide la seconde branche.

**Doublon inter-revendeurs** — faire déclarer le même numéro de série par deux
clients différents. Le second doit ressortir `STATUT = DOUBLON` avec le nom du
premier dans `DOUBLON DE`.

**Idempotence** — relancer le flux sans rien changer : rien ne doit être ajouté.

---

## 9. Points ouverts

- **Le récap promotions n'a pas de colonne SN** et n'en aura pas : les deux
  déclarations vivent dans deux classeurs séparés. Si l'équipe a besoin d'une vue
  consolidée, elle se construit côté Postgres par jointure sur `id_declaration`.
- **Le flux promotions garde sa limite connue** (§4.5 du document principal) :
  si son `Mark as Processing` échoue après un export réussi, la déclaration reste
  en `submitted` et l'insert suivant ne renvoyant rien, elle s'y bloque. Le flux
  SN n'a pas ce défaut, sa branche de marquage étant indépendante de l'export.
  Le même correctif y est applicable.
