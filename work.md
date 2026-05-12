# Journal de travail - Module SNT DJI Compensation

**Date** : 28 avril 2026
**Projet** : Module PrestaShop de compensation DJI + Integration n8n

---

## 28 avril 2026 - Édition déclarations + Export Google Sheets

### 1. Restriction d'édition du fichier XLSX

**Problème** : Les clients pouvaient modifier le fichier XLSX même après soumission de la déclaration.

**Solution** : Le fichier XLSX est maintenant verrouillé après soumission. Seuls les PDF/photos restent éditables.

| Statut | XLSX | PDF/Photos |
|--------|------|------------|
| draft | ✅ éditable | ✅ éditable |
| submitted | ❌ verrouillé | ✅ éditable |
| processing | ❌ verrouillé | ✅ éditable |
| validated/rejected | ❌ | ❌ |

**Fichiers modifiés :**
- `classes/DJICompensationDeclaration.php` : ajout méthode `isXlsxEditable()`
- `controllers/front/declaration.php` : contrôle côté serveur + variable template
- `views/templates/front/declaration.tpl` : affichage conditionnel zone XLSX

---

### 2. Nouvel endpoint API : declarations-summary

**Objectif** : Récupérer toutes les lignes déclarées agrégées par promotion et client pour export Google Sheets.

**Endpoint** : `GET /api/dji-compensation/declarations-summary`

**Paramètres** :
| Paramètre | Type | Description |
|-----------|------|-------------|
| status | string | Filtrer par statut (submitted, processing, validated) |

**Réponse** :
```json
{
  "success": true,
  "total_declarations": 4,
  "total_lines": 10,
  "lines": [
    {
      "id_declaration": 1,
      "declaration_reference": "DJI-8BB47F8E",
      "declaration_status": "processing",
      "declaration_date": "2026-04-24 11:21:59",
      "id_customer": 704,
      "customer_name": "Karl Duponchel",
      "customer_email": "karl.d@snt2.fr",
      "customer_company": "SNT2",
      "customer_siret": null,
      "reference_article": "AR0059565",
      "produit": "Osmo Mobile 7P",
      "ean": "6941565997517",
      "quantite_declaree": 10,
      "prix_promo_ttc": 115,
      "compensation_ht": 14.4,
      "date_debut": "2026-01-01",
      "date_fin": "2026-01-31",
      "date_butoir": "2026-02-06"
    }
  ]
}
```

**Fichiers modifiés :**
- `controllers/front/api.php` : ajout méthode `getDeclarationsSummary()`

---

### 3. Workflow n8n - Export Google Sheets (DONE)

**Architecture** :
```
[Manual Trigger]
        |
        +---> [HTTP Request] GET /declarations-summary
        |              |
        |              v
        |        [Split Out] field: lines
        |              |
        +---> [Postgres] GET type_promotion from promotions_dji
                       |
                       v
               [Merge] Join on reference_article
                       |
                       v
               [Code] Map to Google Sheets columns
                       |
                       v
               [Google Sheets] Append
```

**Endpoint API modifié** : `declarations-summary` retourne maintenant :
- `total_compensation_ht` : calculé côté API (quantite × compensation_ht)
- `numero_serie` : numéro de série du produit (SN)
- `reference_avoir` : référence avoir revendeur

**Colonnes Google Sheets** :
| NOM DU CLIENT | TYPE DE PROMO | DATE DEBUT | DATE FIN | QUANTITE | COMPENSATION HT | TOTAL | REF AVOIR | SN |

**Code Node - Map to Google Sheets Columns** :
```javascript
const items = $input.all();
const results = [];

for (const item of items) {
  const d = item.json;
  
  results.push({
    json: {
      'NOM DU CLIENT': d.customer_company || d.customer_name || '',
      'TYPE DE PROMO': d.type_promotion || '',
      'DATE DEBUT': d.date_debut || '',
      'DATE FIN': d.date_fin || '',
      'QUANTITE': d.quantite_declaree || 0,
      'COMPENSATION HT': d.compensation_ht || 0,
      'TOTAL': d.total_compensation_ht || (d.quantite_declaree * d.compensation_ht) || 0,
      'REF AVOIR': d.reference_avoir || '',
      'SN': d.numero_serie || ''
    }
  });
}

return results;
```

**Configuration Google Sheets** :
1. Importer le workflow `Declarations summary.json`
2. Configurer le credential Google Sheets OAuth2
3. Sélectionner le document et la feuille dans le node "Google Sheets - Append"
4. Mode "Append Row" pour ajouter les lignes

---

### TODO - Actions restantes

- [x] Créer le workflow n8n complet "Export Google Sheets"
- [x] API declarations-summary mise à jour (total_compensation_ht, numero_serie, reference_avoir)
- [x] Configurer le Google Sheets OAuth2 dans n8n
- [ ] Créer workflow n8n "Sync Customers" pour alimenter `prestashop_customers`
- [ ] Ajouter notification Slack/Email en cas d'anomalies (workflow analyse)

---

### 4. Configuration Google Sheets OAuth2 (DONE)

**Date** : 5 mai 2026

#### Étape 1 : Google Cloud Console

1. Aller sur https://console.cloud.google.com/
2. Créer un projet (ou utiliser existant)
3. Activer les APIs :
   - **Google Sheets API**
   - **Google Drive API** (requis pour lister les documents)

#### Étape 2 : Créer les credentials OAuth2

1. **APIs & Services** > **Credentials** > **Create Credentials** > **OAuth client ID**
2. Configurer l'écran de consentement OAuth si demandé :
   - Type : **External** (ou Internal si Google Workspace)
   - Ajouter les emails autorisés dans "Test users"
3. Type d'application : **Web application**
4. **Origines JavaScript autorisées** : `https://ton-domaine-n8n.com` (optionnel)
5. **URI de redirection autorisés** : `https://ton-domaine-n8n.com/rest/oauth2-credential/callback`
6. Récupérer le **Client ID** et **Client Secret**

#### Étape 3 : Configurer dans n8n

1. **Credentials** > **Add Credential** > **Google Sheets OAuth2 API**
2. Entrer Client ID et Client Secret
3. Cliquer **Sign in with Google** et autoriser
4. Sauvegarder

#### Étape 4 : Configurer le noeud Google Sheets

Dans le workflow "Declarations summary" :
1. Ouvrir le noeud **Google Sheets - Append**
2. Sélectionner le credential créé
3. Sélectionner le **Document** cible
4. Sélectionner la **Feuille**
5. Mode : **Append Row**

#### Erreurs courantes

| Erreur | Solution |
|--------|----------|
| "Access blocked: X can only be used within its organization" | Passer l'app en mode **External** dans OAuth consent screen |
| "Google Drive API has not been used" | Activer l'API Google Drive dans le projet |
| Workflow succès mais rien inséré | Vérifier que documentId et sheetName sont configurés dans le noeud |

---

## 22 avril 2026 - Correction contrainte FK

### Problème rencontré

Lors du TRUNCATE de `promotions_dji` dans n8n :
```
cannot truncate a table referenced in a foreign key constraint
Table "factures_revendeurs_lignes" references "promotions_dji"
```

### Analyse

La FK `factures_revendeurs_lignes.promo_reference_article → promotions_dji.reference_article` bloque le TRUNCATE.

**Constat** : Cette FK n'est pas nécessaire. `promotions_dji` est une table de référence volatile (rafraîchie quotidiennement). La relation est purement logique — une jointure au moment du contrôle de prix suffit.

### Solution appliquée

Suppression de la contrainte FK (l'index reste pour les performances de jointure) :

```sql
ALTER TABLE factures_revendeurs_lignes 
DROP CONSTRAINT factures_revendeurs_lignes_promo_reference_article_fkey;
```

**Exécution** : Via un noeud Postgres dans n8n (Execute Query), à exécuter une seule fois.

---

## 21 avril 2026 - Implémentation initiale

---

## Resume des fonctionnalites implementees

### 1. Notifications d'erreur par email

**Fichiers crees :**
- `classes/DJICompensationMailService.php` - Service d'envoi d'emails
- `mails/fr/upload_error.html` - Template HTML
- `mails/fr/upload_error.txt` - Template texte
- `mails/index.php` et `mails/fr/index.php` - Fichiers de securite

**Fichiers modifies :**
- `snt_dji_compensation.php` - Configuration (email admin, activation notifications)
- `controllers/front/declaration.php` - Appel du service en cas d'erreur

**Configuration ajoutee :**
- `SNT_DJI_COMPENSATION_NOTIFY_ERRORS` - Active/desactive les notifications
- `SNT_DJI_COMPENSATION_ADMIN_EMAIL` - Email destinataire

**Cas couverts :**
- Erreur de configuration S3
- Echec upload XLSX/PDF/images vers S3
- Echec sauvegarde en base de donnees

---

### 2. API REST pour integration externe (n8n)

**Fichiers crees :**
- `controllers/front/api.php` - Controleur API REST

**Fichiers modifies :**
- `snt_dji_compensation.php` - Route API, configuration cle API, formulaire
- `classes/DJICompensationDeclaration.php` - Ajout methode `countByCustomer()`

**Configuration ajoutee :**
- `SNT_DJI_COMPENSATION_API_KEY` - Cle API generee automatiquement

**Endpoints disponibles :**

| Endpoint | Description |
|----------|-------------|
| `GET /api/dji-compensation/customers` | Liste des clients avec declarations |
| `GET /api/dji-compensation/customer?id_customer=ID` | Detail d'un client + declarations |
| `GET /api/dji-compensation/declarations` | Liste des declarations |
| `GET /api/dji-compensation/stats` | Statistiques globales |
| `GET /api/dji-compensation/template` | Contenu du fichier XLSX promotions |

**Authentification :**
```
Authorization: Bearer VOTRE_CLE_API
# ou
X-Api-Key: VOTRE_CLE_API
# ou
?api_key=VOTRE_CLE_API
```

---

### 3. Lecteur XLSX natif

**Fichier cree :**
- `classes/DJICompensationXlsxReader.php` - Parseur XLSX sans dependance externe

**Fonctionnalites :**
- Lit les fichiers XLSX (format ZIP contenant XML)
- Supporte plusieurs feuilles
- Parametre `headers_row` pour specifier la ligne des en-tetes
- Pagination avec `limit` et `offset`
- Retourne les donnees avec headers comme cles JSON

---

## Integration n8n - Workflow quotidien

### Architecture du workflow

```
[Schedule Trigger]     Tous les jours a 6h
        |
        v
[HTTP Request]         GET /api/dji-compensation/template
        |
        v
[Code]                 Mapping colonnes + conversion dates Excel
        |
        v
[Postgres]             TRUNCATE puis INSERT
```

### Configuration HTTP Request

- **Method** : GET
- **URL** : `https://dev-prestashop-xxx.prestashop.sh/index.php?fc=module&module=snt_dji_compensation&controller=api&action=template`
- **Headers** : `Authorization: Bearer TA_CLE_API`

### Code Node - Mapping des colonnes

```javascript
// Fonction pour convertir une date Excel en date ISO (YYYY-MM-DD)
function excelDateToISO(excelDate) {
  if (!excelDate || isNaN(excelDate)) {
    return null;
  }
  const date = new Date((excelDate - 25569) * 86400 * 1000);
  return date.toISOString().split('T')[0];
}

// Fonction pour nettoyer les valeurs numeriques
function parseNumber(value) {
  if (!value || value === '') {
    return null;
  }
  const num = parseFloat(String(value).replace(',', '.').replace(/[^\d.-]/g, ''));
  return isNaN(num) ? null : num;
}

// Fonction pour nettoyer une chaine
function cleanString(value) {
  if (!value) return null;
  const cleaned = String(value).trim();
  return cleaned === '' ? null : cleaned;
}

// Les donnees sont dans data
const inputData = $input.first().json;
const items = inputData.data;

if (!items || !Array.isArray(items)) {
  throw new Error('Pas de donnees trouvees');
}

// Mapper et transformer chaque ligne
const result = [];

for (const row of items) {
  // Ignorer les lignes sans produit OU sans reference
  const produit = cleanString(row['Produit']);
  const reference = cleanString(row['Référence produit - STS']);
  
  if (!produit || !reference) {
    continue; // Ignorer cette ligne
  }
  
  result.push({
    json: {
      statut: cleanString(row['Statut']),
      type_promotion: cleanString(row['Type de promotion']),
      produit: produit,
      ean: cleanString(row['EAN Produit']),
      reference_article: reference,
      date_debut: excelDateToISO(row['Date de début']),
      date_fin: excelDateToISO(row['Date de fin']),
      prix_vente_ttc: parseNumber(row['Prix de vente TTC']),
      nouveau_prix_ttc: parseNumber(row['Prix Promo TTC']),
      difference: parseNumber(row['Baisse de prix']),
      compensation_ht: parseNumber(row['Compensation €HT']),
      date_butoir_declaration: excelDateToISO(row['Date butoir de déclaration']),
      mise_a_jour: excelDateToISO(row['Mise à Jour']),
      declaration_sts_dji: cleanString(row['Déclaration de STS à DJI'])
    }
  });
}

return result;
```

### Mapping colonnes XLSX → PostgreSQL

| Colonne XLSX | Colonne PostgreSQL |
|--------------|-------------------|
| Statut | statut |
| Type de promotion | type_promotion |
| Produit | produit |
| EAN Produit | ean |
| Référence produit - STS | reference_article |
| Date de début | date_debut |
| Date de fin | date_fin |
| Prix de vente TTC | prix_vente_ttc |
| Prix Promo TTC | nouveau_prix_ttc |
| Baisse de prix | difference |
| Compensation €HT | compensation_ht |
| Date butoir de déclaration | date_butoir_declaration |
| Mise à Jour | mise_a_jour |
| Déclaration de STS à DJI | declaration_sts_dji |

---

## Base de donnees PostgreSQL

### Connexion (credential n8n)

| Champ | Valeur |
|-------|--------|
| Host | `app_postgres` |
| Database | `app_db` |
| User | `app_user` |
| Password | `app_password` |
| Port | `5432` |

### Table promotions_dji

```sql
CREATE TABLE IF NOT EXISTS public.promotions_dji (
    statut                    VARCHAR(50),
    type_promotion            VARCHAR(100),
    produit                   TEXT          NOT NULL,
    ean                       VARCHAR(30),
    reference_article         VARCHAR(50),
    date_debut                DATE,
    date_fin                  DATE,
    prix_vente_ttc            NUMERIC(10,2),
    nouveau_prix_ttc          NUMERIC(10,2),
    difference                NUMERIC(10,2),
    compensation_ht           NUMERIC(10,2),
    date_butoir_declaration   DATE,
    mise_a_jour               DATE,
    declaration_sts_dji       VARCHAR(100),
    created_at                TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);
```

### Modification de la contrainte (a faire)

La contrainte UNIQUE sur `reference_article` doit etre modifiee car un meme article peut avoir plusieurs promos sur des dates differentes :

```sql
-- Supprimer la contrainte unique sur reference_article
ALTER TABLE promotions_dji DROP CONSTRAINT IF EXISTS promotions_dji_reference_article_key;

-- Option 1 : Contrainte sur article + dates
ALTER TABLE promotions_dji 
ADD CONSTRAINT promotions_dji_unique_promo 
UNIQUE (reference_article, date_debut, date_fin);

-- Option 2 : Pas de contrainte, TRUNCATE + INSERT quotidien
```

---

## TODO - Workflow Promotions (DONE)

- [x] Contrainte UNIQUE retirée
- [x] Header "Statut" corrigé
- [x] TRUNCATE + INSERT quotidien configuré

---

## 24 avril 2026 - Workflow Analyse des Déclarations (DONE)

### Objectif

Analyser automatiquement les déclarations clients :
1. Parser le XLSX client pour extraire les lignes déclarées
2. Analyser les factures PDF via Mistral API (OCR)
3. Vérifier la cohérence : quantités déclarées vs quantités facturées vs prix promotions
4. Mettre à jour le statut de la déclaration dans PrestaShop

### Endpoints API ajoutés

| Endpoint | Méthode | Description |
|----------|---------|-------------|
| `/api/dji-compensation/declarations-to-process` | GET | Déclarations status=submitted à traiter |
| `/api/dji-compensation/declaration-xlsx?id_declaration=X` | GET | Lignes déclarées du XLSX client |
| `/api/dji-compensation/invoices-urls?id_declaration=X` | GET | URLs S3 pré-signées des factures PDF |
| `/api/dji-compensation/update-status` | GET/POST | Mettre à jour le statut d'une déclaration |

### Tables PostgreSQL

Script complet : `scripts-sql/create_analyse_tables.sql`

Tables créées :
- `declaration_lignes` - Lignes déclarées parsées du XLSX client
- `factures_analysees` - Métadonnées des factures analysées par Mistral
- `factures_produits` - Produits extraits des factures (OCR)
- `verifications_anomalies` - Résultats de vérification (OK ou anomalie)

Types d'anomalies :
- `ok` - Ligne vérifiée sans problème
- `article_non_facture` - Article déclaré non trouvé dans les factures
- `quantite_mismatch` - Écart entre quantité déclarée et facturée
- `prix_mismatch` - Écart de prix > 5€ vs promotion

### Workflow n8n - Architecture finale

```
[Schedule Trigger]
        |
        v
[HTTP] GET /declarations-to-process
        |
        v
[Split] Pour chaque déclaration
        |
        v
[HTTP] GET /declaration-xlsx ──────────────────────────────────┐
        |                                                       |
        v                                                       |
[Code] Transform Declaration Lines                             |
        |                                                       |
        v                                                       |
[Postgres] INSERT declaration_lignes                           |
        |                                                       |
        v                                                       |
[HTTP] GET /invoices-urls                                      |
        |                                                       |
        v                                                       |
[Code] Remove Duplicates + Prepare Mistral                     |
        |                                                       |
        v                                                       |
[HTTP] POST Mistral API (avec délai 2s entre chaque)           |
        |                                                       |
        v                                                       |
[Code] Parse Mistral Response                                  |
        |                                                       |
        v                                                       |
[Postgres] INSERT factures_analysees (return id)               |
        |                                                       |
        v                                                       |
[Code] Prepare Facture Produits                                |
        |                                                       |
        v                                                       |
[Postgres] INSERT factures_produits                            |
        |                                                       |
        v                                                       |
[Postgres] GET Declaration Lignes (Execute Once)               |
        |                                                       |
        v                                                       |
[Postgres] GET Factures Produits (Execute Once)                |
        |                                                       |
        v                                                       |
[Postgres] GET Promotions DJI (Execute Once)                   |
        |                                                       |
        v                                                       |
[Code] Verify Anomalies (match EAN > Ref > Designation)        |
        |                                                       |
        v                                                       |
[Postgres] INSERT verifications_anomalies                      |
        |                                                       |
        v                                                       |
[Code] Determine Status                                        |
        |                                                       |
        v                                                       |
[HTTP] GET /update-status?id_declaration=X&status=Y ───────────┘
```

### Code Node - Prepare Mistral Request

```javascript
const prompt = `Tu es un expert en comptabilité française. Extrais les informations de cette facture et retourne UNIQUEMENT un JSON valide, sans markdown, sans explication, sans texte avant ou après le JSON.

=== RÈGLES GÉNÉRALES ===
- Tous les montants utilisent le POINT comme séparateur décimal (ex: 1 129,00 → 1129.00)
- Les espaces dans les nombres sont des séparateurs de milliers à ignorer
- quantite peut être négative (avoir, remise, retour)
- Si le document est un AVOIR, traiter comme une facture avec des montants négatifs

=== IDENTIFICATION DU DOCUMENT ===
- numero_facture : chercher après "Facture n°", "FACTURE n°", "N° facture", "#FA", "FA-". Si avoir : chercher après "Avoir", "AV-". Une date n'est JAMAIS un numéro de facture.
- date_facture : date d'ÉMISSION au format YYYY-MM-DD (ex: 05/01/2026 → 2026-01-05)

=== ARTICLES ===
Pour chaque ligne produit (IGNORER les lignes de sous-total, total, TVA, frais de port) :

- ean : UNIQUEMENT une suite de EXACTEMENT 13 chiffres consécutifs sans aucune lettre. null si absent ou invalide.
- numero_serie : suite alphanumérique longue (10-20 caractères). Chercher après "N/S :", "N°SERIE:", "SN:", "S/N:". null si absent.
- reference : code ou référence interne du vendeur. null si absent.
- designation : nom COMPLET du produit tel qu'affiché, SANS le numéro de série.
- quantite : nombre d'unités. Négatif pour les avoirs/retours.
- prix_unitaire_ttc : prix TTC d'UN article. Calculer depuis HT×1.20 si seul HT disponible.

IGNORER : Remises, frais de port, garanties, lignes quantité = 0

=== STRUCTURE JSON ===
{"numero_facture":"","date_facture":"YYYY-MM-DD","total_ttc":0.00,"lignes":[{"ean":null,"numero_serie":null,"reference":null,"designation":"","quantite":1,"prix_unitaire_ttc":0.00}]}`;

const results = [];
for (const item of $input.all()) {
  results.push({
    json: {
      ...item.json,
      mistral_body: {
        model: "mistral-large-latest",
        messages: [
          {
            role: "user",
            content: [
              { type: "text", text: prompt },
              { type: "document_url", document_url: item.json.presigned_url }
            ]
          }
        ],
        response_format: { type: "json_object" }
      }
    }
  });
}
return results;
```

### Code Node - Verify Anomalies (complet)

```javascript
const TOLERANCE_PRIX = 5.00;

const declarationLignes = $('GET Declaration Lignes').all();
const facturesProduits = $('GET Factures Produits').all();
const promotions = $('GET Promotions DJI').all();
const idDeclaration = $('GET Declaration XLSX').first().json.id_declaration;

const resultats = [];

// Index des produits facturés
const factures = facturesProduits.map(p => p.json);

// Index des promotions par EAN et par référence
const promosByEan = {};
const promosByRef = {};
const promosByName = [];

for (const promo of promotions) {
  const p = promo.json;
  if (p.ean) promosByEan[p.ean.trim()] = p;
  if (p.reference_article) promosByRef[p.reference_article.toUpperCase().trim()] = p;
  promosByName.push({
    name: (p.produit || '').toUpperCase(),
    promo: p
  });
}

// Fonction de match partiel (au moins 2 mots en commun)
function matchPartiel(designation, promosName) {
  const words = designation.toUpperCase().replace(/[^A-Z0-9\s]/g, '').split(/\s+/).filter(w => w.length > 2);
  let bestMatch = null;
  let bestScore = 0;

  for (const item of promosName) {
    const promoWords = item.name.replace(/[^A-Z0-9\s]/g, '').split(/\s+/).filter(w => w.length > 2);
    const common = words.filter(w => promoWords.some(pw => pw.includes(w) || w.includes(pw)));
    const score = common.length;

    if (score >= 2 && score > bestScore) {
      bestScore = score;
      bestMatch = item.promo;
    }
  }
  return bestMatch;
}

// Fonction pour trouver une promo : EAN > Référence > Nom partiel
function findPromo(ligne) {
  if (ligne.ean && promosByEan[ligne.ean.trim()]) {
    return { promo: promosByEan[ligne.ean.trim()], method: 'ean' };
  }
  const ref = (ligne.reference_article || ligne.reference || '').toUpperCase().trim();
  if (ref && promosByRef[ref]) {
    return { promo: promosByRef[ref], method: 'reference' };
  }
  const designation = ligne.designation || ligne.produit || '';
  if (designation) {
    const match = matchPartiel(designation, promosByName);
    if (match) {
      return { promo: match, method: 'designation_partielle' };
    }
  }
  return null;
}

// Agréger les quantités facturées par produit
const factureAggregated = {};
for (const f of factures) {
  if (f.quantite <= 0 || f.prix_unitaire_ttc < 0) continue;
  const match = findPromo(f);
  if (match) {
    const key = match.promo.reference_article.toUpperCase().trim();
    if (!factureAggregated[key]) {
      factureAggregated[key] = {
        quantite: 0,
        prix_unitaire: parseFloat(f.prix_unitaire_ttc) || 0,
        promo: match.promo,
        method: match.method
      };
    }
    factureAggregated[key].quantite += f.quantite;
  }
}

// Vérifier chaque ligne déclarée
for (const ligne of declarationLignes) {
  const l = ligne.json;
  const ref = (l.reference_article || '').toUpperCase().trim();
  if (!ref) continue;

  const qteDeclaree = l.quantite_declaree || 0;
  const facture = factureAggregated[ref];
  const promo = promosByRef[ref];

  // 1. Article non trouvé dans les factures
  if (!facture) {
    resultats.push({
      json: {
        id_declaration: idDeclaration,
        reference_article: ref,
        type_anomalie: 'article_non_facture',
        quantite_declaree: qteDeclaree,
        quantite_facturee: 0,
        prix_facture: null,
        prix_promo: promo ? parseFloat(promo.nouveau_prix_ttc) : null,
        ecart_prix: null,
        message: `Article ${ref} déclaré (${qteDeclaree} unités) non trouvé dans les factures`
      }
    });
    continue;
  }

  let hasAnomaly = false;

  // 2. Écart de quantité
  if (facture.quantite !== qteDeclaree) {
    hasAnomaly = true;
    resultats.push({
      json: {
        id_declaration: idDeclaration,
        reference_article: ref,
        type_anomalie: 'quantite_mismatch',
        quantite_declaree: qteDeclaree,
        quantite_facturee: facture.quantite,
        prix_facture: facture.prix_unitaire,
        prix_promo: promo ? parseFloat(promo.nouveau_prix_ttc) : null,
        ecart_prix: null,
        message: `Qté déclarée (${qteDeclaree}) ≠ facturée (${facture.quantite}) [match: ${facture.method}]`
      }
    });
  }

  // 3. Écart de prix (tolérance 5€)
  if (promo && facture.prix_unitaire !== null) {
    const prixPromo = parseFloat(promo.nouveau_prix_ttc) || 0;
    const prixFacture = parseFloat(facture.prix_unitaire) || 0;
    const ecart = Math.abs(prixFacture - prixPromo);

    if (ecart > TOLERANCE_PRIX) {
      hasAnomaly = true;
      resultats.push({
        json: {
          id_declaration: idDeclaration,
          reference_article: ref,
          type_anomalie: 'prix_mismatch',
          quantite_declaree: qteDeclaree,
          quantite_facturee: facture.quantite,
          prix_facture: prixFacture,
          prix_promo: prixPromo,
          ecart_prix: parseFloat(ecart.toFixed(2)),
          message: `Prix facture (${prixFacture}€) ≠ promo (${prixPromo}€), écart ${ecart.toFixed(2)}€`
        }
      });
    }
  }

  // 4. Ligne OK
  if (!hasAnomaly) {
    const prixFacture = facture.prix_unitaire ? parseFloat(facture.prix_unitaire) : null;
    const prixPromo = promo ? parseFloat(promo.nouveau_prix_ttc) : null;
    
    resultats.push({
      json: {
        id_declaration: idDeclaration,
        reference_article: ref,
        type_anomalie: 'ok',
        quantite_declaree: qteDeclaree,
        quantite_facturee: facture.quantite,
        prix_facture: prixFacture,
        prix_promo: prixPromo,
        ecart_prix: prixFacture && prixPromo ? parseFloat(Math.abs(prixFacture - prixPromo).toFixed(2)) : null,
        message: `Article ${ref} vérifié OK [match: ${facture.method}]`
      }
    });
  }
}

return resultats;
```

### Code Node - Determine Status

```javascript
const resultats = $('Verify Anomalies').all();
const idDeclaration = $('GET Declaration XLSX').first().json.id_declaration;

const hasAnomalies = resultats.some(r => r.json.type_anomalie !== 'ok');
const nbAnomalies = resultats.filter(r => r.json.type_anomalie !== 'ok').length;
const nbOk = resultats.filter(r => r.json.type_anomalie === 'ok').length;

return [{
  json: {
    id_declaration: idDeclaration,
    status: hasAnomalies ? 'processing' : 'validated',
    admin_notes: hasAnomalies 
      ? `${nbAnomalies} anomalie(s) détectée(s) sur ${resultats.length} lignes`
      : `Validé automatiquement - ${nbOk} lignes vérifiées OK`
  }
}];
```

### Configuration importante

- **Mistral API** : Ajouter un délai de 2 secondes entre chaque appel (rate limiting)
- **Nodes Postgres GET** : Activer "Execute Once" pour éviter la multiplication des résultats
- **Tolérance prix** : 5€ (configurable dans le code)

### Requête de reset pour tests

```sql
TRUNCATE TABLE 
  verifications_anomalies,
  factures_produits,
  factures_analysees,
  declaration_lignes
RESTART IDENTITY CASCADE;
```

### TODO - Améliorations futures

- [ ] Ajouter notification Slack/Email en cas d'anomalies
- [ ] Table d'association client → charte de nommage produit
- [ ] Dashboard de suivi des analyses

---

## Fichiers du module modifies/crees

```
snt_dji_compensation/
├── classes/
│   ├── DJICompensationDeclaration.php    (modifie - isXlsxEditable())
│   ├── DJICompensationMailService.php    (cree)
│   └── DJICompensationXlsxReader.php     (cree)
├── controllers/
│   └── front/
│       ├── api.php                        (modifie - declarations-summary)
│       └── declaration.php                (modifie - restriction XLSX)
├── mails/
│   ├── index.php                          (cree)
│   └── fr/
│       ├── index.php                      (cree)
│       ├── upload_error.html              (cree)
│       └── upload_error.txt               (cree)
├── n8n_workflows/
│   ├── DJI Compensation - Analyse Declarations.json
│   ├── [DONE] Insert Actual Promotions.json
│   └── [DONE] TRUNCATE promotions_dji.json
├── scripts-sql/
│   ├── 01_create_table_promotions_dji.sql
│   ├── 02_create_table_factures_revendeur.sql
│   ├── 03_create_table_prestashop_customers.sql
│   └── 04_create_analyse_tables.sql
├── views/templates/front/
│   └── declaration.tpl                    (modifie - zone XLSX conditionnelle)
├── snt_dji_compensation.php               (modifie)
├── readme.md                              (modifie)
└── work.md                                (ce fichier)
```
