# Synchro des promotions — `promotions_dji`

`promotions_dji` est le référentiel des promotions DJI. Les flux « Declarations
summary » et « Declarations SN summary » y retrouvent, pour chaque ligne déclarée,
le type de promo et les dates officielles (rattachement : même référence et même
date de début, sinon période contenant la date, sinon promo unique de la référence).

Sa source est le **template actif** du back-office (onglet 1, « Promotions et
Baisses de prix »), servi par `GET …&controller=api&action=template`.

## 1. Déclenchement

```
Admin uploade / restaure un template ─► module ─► POST webhook n8n ─┐
Cron n8n tous les jours à 5h (Europe/Paris) ────────────────────────┼─► flux « Insert Actual Promotions »
Clic manuel (tests) ────────────────────────────────────────────────┘
```

- **Webhook** : `processTemplateUpload()` et `processRestoreTemplate()` appellent
  `notifyPromotionsSync()` une fois le template actif changé. L'appel est en
  *best effort* (3 s de connexion, 5 s au total) : en cas d'échec le template reste
  enregistré, l'admin voit un avertissement et l'erreur part dans les logs
  PrestaShop. Le cron rattrape.
- L'URL se règle dans **Module › Configuration API › Webhook n8n - synchro
  promotions**. Vide = pas d'appel.
- Le module s'authentifie avec **sa clé API** dans le header `X-Api-Key` : le
  credential *Header Auth* du webhook n8n doit porter la même valeur. Régénérer la
  clé API impose donc de mettre à jour ce credential (et le Bearer du node HTTP).

## 2. Le flux

`GET Template actif` → `Map promotions` (Code) → `Upsert promotions_dji` (Postgres)

- **Map promotions** retrouve les colonnes par préfixe d'en-tête, ce qui tolère
  les titres sur plusieurs lignes (« Type d'opération\n1. Promotion… », « Date
  butoir de déclaration \n(délai…) »). Les doublons du fichier sur le trio
  (référence, début, fin) sont fusionnés, la dernière ligne l'emporte. Si la
  réponse est invalide ou ne contient aucune promo, le node **échoue avant toute
  écriture**.
- **Upsert promotions_dji** envoie toutes les lignes en un seul paramètre JSON
  (`jsonb_to_recordset`) : une seule requête, donc atomique.
  `ON CONFLICT (reference_article, date_debut, COALESCE(date_fin, '9999-12-31'))`
  → insertion ou mise à jour ; une ligne identique n'est pas réécrite
  (`updated_at` garde la date du dernier vrai changement). Le node renvoie
  `lignes_envoyees`, `inserees`, `mises_a_jour`, `inchangees`.

**Plus de TRUNCATE.** Une promo retirée du template reste en base, pour qu'une
déclaration tardive la retrouve encore. Contrepartie : si STS corrige les dates
d'une promo, l'ancienne version reste aussi (elle ne gêne pas le rattachement, qui
part de la date de début déclarée). Pour purger, supprimer la ligne à la main.

## 3. Mise en place

1. **SQL** — exécuter `scripts-sql/08_promotions_upsert.sql` (rejouable) :
   passe `type_promotion` en `TEXT`, dédoublonne, crée l'index unique.
   Sans cet index, l'upsert échoue (`there is no unique or exclusion constraint
   matching the ON CONFLICT specification`).
2. **n8n** — importer `n8n_workflows/Insert Actual Promotions.json`, puis :
   - node *Webhook template actif* : créer un credential **Header Auth**
     (Name `X-Api-Key`, Value = clé API du module) ;
   - vérifier les credentials Bearer (HTTP) et Postgres ;
   - activer le flux, copier la **Production URL** du webhook.
   Le sous-flux « TRUNCATE promotions_dji » n'est plus appelé : l'archiver.
3. **Module** — déployer `snt_dji_compensation.php`, coller l'URL dans la
   configuration API, enregistrer.
4. **Test** — bouton **« Synchroniser les promotions maintenant »** de la
   configuration API : appelle le webhook avec le template actif, sans le
   changer. L'exécution doit apparaître dans les *Executions* n8n dans la
   seconde, avec `inchangees` = nombre de promos si rien n'a bougé.

## 4. Tests

`scripts-sql/tests/test_promotions_upsert.py` (lit la requête dans le workflow) :
migration rejouable et dédoublonnage, insertion, ligne inchangée non réécrite,
mise à jour, promo retirée conservée, `date_fin` NULL, libellé de plus de
100 caractères.
