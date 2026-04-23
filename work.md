# Journal de travail - Module SNT DJI Compensation

**Date** : 22 avril 2026
**Projet** : Module PrestaShop de compensation DJI + Integration n8n

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

## TODO - A reprendre demain

1. [ ] Modifier la contrainte UNIQUE de la table `promotions_dji`
2. [ ] Choisir la strategie : 
   - **Option A** : UPSERT avec contrainte sur (reference_article, date_debut, date_fin)
   - **Option B** : TRUNCATE + INSERT (recommande pour sync quotidienne)
3. [ ] Finaliser le noeud Postgres dans n8n
4. [ ] Configurer le Schedule Trigger (tous les jours a 6h par exemple)
5. [ ] Tester le workflow complet
6. [ ] Corriger le header "Statut" dans le fichier XLSX (actuellement "AR0063215")

---

## Fichiers du module modifies/crees

```
snt_dji_compensation/
├── classes/
│   ├── DJICompensationDeclaration.php    (modifie)
│   ├── DJICompensationMailService.php    (cree)
│   └── DJICompensationXlsxReader.php     (cree)
├── controllers/
│   └── front/
│       ├── api.php                        (cree)
│       └── declaration.php                (modifie)
├── mails/
│   ├── index.php                          (cree)
│   └── fr/
│       ├── index.php                      (cree)
│       ├── upload_error.html              (cree)
│       └── upload_error.txt               (cree)
├── snt_dji_compensation.php               (modifie)
└── Readme.md                              (modifie)
```
