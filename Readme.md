# SNT Compensation DJI

Module PrestaShop permettant aux clients B2B d'effectuer leurs declarations de compensation DJI lors de periodes de promotion.

## Fonctionnalites

### Cote Client (Front-Office)

- **Acces depuis l'espace client** : Lien dans la section "Mon compte"
- **Telechargement du template** : Les clients peuvent telecharger le fichier XLSX des promotions DJI a remplir
- **Soumission de declaration** :
  - Upload du fichier XLSX complete
  - Upload de factures PDF (jusqu'a 50 fichiers)
  - Upload de photos de stock pour les produits DJI Mic/Power (jusqu'a 20 images)
- **Systeme de brouillon** : Les clients peuvent sauvegarder leur progression et reprendre plus tard
- **Edition des declarations** : 
  - Brouillon : XLSX + PDF/photos editables
  - Soumise/En traitement : PDF/photos editables uniquement (XLSX verrouille)
  - Validee/Rejetee : Aucune modification possible
- **Historique** : Visualisation de l'historique des declarations avec leur statut

### Cote Administrateur (Back-Office)

- **Liste des declarations** : Tableau avec filtres par statut, client, societe
- **Consultation detaillee** : Vue complete d'une declaration avec tous les fichiers joints
- **Gestion des statuts** :
  - Brouillon
  - Soumise
  - En traitement
  - Validee
  - Rejetee
- **Notes administrateur** : Ajout de commentaires internes sur chaque declaration
- **Telechargement des fichiers** : Acces aux fichiers via URLs pre-signees S3

### Stockage S3 (Scaleway)

- Tous les fichiers sont stockes sur Scaleway Object Storage (compatible S3)
- Structure organisee : `declarations/{id_customer}/{id_declaration}/`
- URLs pre-signees pour un acces securise temporaire
- Test de connexion integre

## Configuration

### Configuration Generale

| Parametre | Description | Defaut |
|-----------|-------------|--------|
| Mode actif | Active/desactive le module pour les clients | Non |
| Taille max. fichier | Taille maximale par fichier en Mo | 10 Mo |
| Nombre max. factures | Nombre maximum de factures par declaration | 50 |
| Nombre max. photos stock | Nombre maximum de photos de stock | 20 |
| Notifier les erreurs d'upload | Envoyer un email en cas d'erreur d'upload | Oui |
| Email de notification | Adresse email pour les notifications d'erreur | Email boutique |

### Notifications d'erreur

Le module peut envoyer des emails de notification en cas d'erreur lors :
- De l'upload de fichiers XLSX, PDF ou images
- De la connexion ou du transfert vers le stockage S3
- De la sauvegarde des declarations en base de donnees

Les emails contiennent :
- Le type d'erreur et le message detaille
- Les informations du client (nom, email, societe)
- L'identifiant de la declaration concernee
- Le nom du fichier en erreur

### Configuration S3

| Parametre | Description | Exemple |
|-----------|-------------|---------|
| Region | Region Scaleway | fr-par |
| Nom du bucket | Nom du bucket S3 | - |
| Access Key | Cle d'acces S3 | - |
| Secret Key | Cle secrete S3 | - |

### Template XLSX

L'administrateur doit uploader le fichier XLSX des promotions DJI que les clients devront telecharger et remplir.

## Structure des tables

- `dji_compensation_declaration` : Declarations des clients
- `dji_compensation_invoice` : Factures PDF associees
- `dji_compensation_stock_proof` : Photos de stock (produits Mic/Power)

## Routes

| URL | Description |
|-----|-------------|
| `/mon-compte/compensation-dji` | Page de declaration client |
| `/mon-compte/compensation-dji/template` | Telechargement du template XLSX |

## API REST

Le module expose une API REST pour l'integration avec des outils externes (n8n, etc.).

### Authentification

L'API requiert une cle API configurable dans le back-office. Trois methodes d'authentification sont supportees :

```
# Header Authorization Bearer
Authorization: Bearer VOTRE_CLE_API

# Header X-Api-Key
X-Api-Key: VOTRE_CLE_API

# Parametre GET
?api_key=VOTRE_CLE_API
```

### Endpoints

#### GET /api/dji-compensation/customers

Liste tous les clients ayant des declarations.

**Parametres :**
| Parametre | Type | Description |
|-----------|------|-------------|
| only_with_declarations | bool | Filtrer uniquement les clients avec declarations (defaut: true) |
| limit | int | Nombre max de resultats |
| offset | int | Decalage pour pagination |

**Reponse :**
```json
{
  "success": true,
  "total": 150,
  "count": 50,
  "offset": 0,
  "customers": [
    {
      "id_customer": 321,
      "firstname": "Jean",
      "lastname": "Dupont",
      "email": "jean.dupont@example.com",
      "company": "Ma Societe",
      "siret": "12345678901234",
      "customer_since": "2024-01-15 10:30:00",
      "declarations_count": 3,
      "s3_path": "declarations/321/"
    }
  ]
}
```

#### GET /api/dji-compensation/customer

Recupere les details d'un client specifique avec ses declarations.

**Parametres :**
| Parametre | Type | Description |
|-----------|------|-------------|
| id_customer | int | ID du client (requis) |

**Reponse :**
```json
{
  "success": true,
  "customer": {
    "id_customer": 321,
    "firstname": "Jean",
    "lastname": "Dupont",
    "email": "jean.dupont@example.com",
    "company": "Ma Societe",
    "siret": "12345678901234",
    "customer_since": "2024-01-15 10:30:00",
    "s3_path": "declarations/321/",
    "declarations_count": 2,
    "declarations": [
      {
        "id_declaration": 45,
        "reference": "DJI-A1B2C3D4",
        "status": "validated",
        "status_label": "Validee",
        "has_mic_power_products": true,
        "xlsx_filename": "declaration_q1_2024.xlsx",
        "s3_path": "declarations/321/45/",
        "date_add": "2024-03-01 14:22:00",
        "date_upd": "2024-03-05 09:15:00"
      }
    ]
  }
}
```

#### GET /api/dji-compensation/declarations

Liste toutes les declarations.

**Parametres :**
| Parametre | Type | Description |
|-----------|------|-------------|
| status | string | Filtrer par statut (draft, submitted, processing, validated, rejected) |
| id_customer | int | Filtrer par client |
| limit | int | Nombre max de resultats (defaut: 50) |
| offset | int | Decalage pour pagination |

#### GET /api/dji-compensation/stats

Retourne des statistiques globales.

**Reponse :**
```json
{
  "success": true,
  "stats": {
    "total_declarations": 250,
    "by_status": {
      "draft": {"label": "Brouillon", "count": 12},
      "submitted": {"label": "Soumise", "count": 45},
      "processing": {"label": "En traitement", "count": 23},
      "validated": {"label": "Validee", "count": 156},
      "rejected": {"label": "Rejetee", "count": 14}
    },
    "total_customers": 89
  }
}
```

#### GET /api/dji-compensation/template

Retourne le contenu du fichier template XLSX des promotions DJI.

**Parametres :**
| Parametre | Type | Description |
|-----------|------|-------------|
| sheet | int | Index de la feuille (defaut: 1) |
| sheet_name | string | Nom de la feuille (prioritaire sur sheet) |
| headers_row | int | Ligne des en-tetes (defaut: 1) |
| limit | int | Nombre max de lignes |
| offset | int | Decalage pour pagination |

**Reponse :**
```json
{
  "success": true,
  "filename": "template_dji_2024-04-15.xlsx",
  "sheet": "Promotions",
  "available_sheets": ["Promotions", "Instructions"],
  "headers_row": 1,
  "total_rows": 150,
  "returned_rows": 150,
  "offset": 0,
  "headers": ["Reference", "Produit", "Prix", "Remise"],
  "data": [
    {
      "Reference": "DJI-001",
      "Produit": "Mavic 3",
      "Prix": "1599.00",
      "Remise": "10%"
    }
  ]
}
```

#### GET /api/dji-compensation/declarations-to-process

Retourne les declarations avec status=submitted, pretes a etre analysees.

**Parametres :**
| Parametre | Type | Description |
|-----------|------|-------------|
| limit | int | Nombre max de resultats (defaut: 50) |
| offset | int | Decalage pour pagination |

**Reponse :**
```json
{
  "success": true,
  "total": 5,
  "count": 5,
  "offset": 0,
  "declarations": [
    {
      "id_declaration": 1,
      "id_customer": 704,
      "reference": "DJI-A1B2C3D4",
      "status": "submitted",
      "invoices_count": 3,
      "stock_proofs_count": 0
    }
  ]
}
```

#### GET /api/dji-compensation/declaration-xlsx

Retourne les lignes declarees du fichier XLSX client (lignes avec quantite remplie).

**Parametres :**
| Parametre | Type | Description |
|-----------|------|-------------|
| id_declaration | int | ID de la declaration (requis) |

**Reponse :**
```json
{
  "success": true,
  "id_declaration": 1,
  "id_customer": 704,
  "reference": "DJI-A1B2C3D4",
  "total_rows": 150,
  "declared_lines_count": 4,
  "declared_lines": [
    {
      "reference_article": "AR0057378",
      "produit": "Osmo Action 5 Pro",
      "ean": "6941565981066",
      "quantite_declaree": 2,
      "prix_promo_ttc": 318.99,
      "compensation_ht": 18.01
    }
  ]
}
```

#### GET /api/dji-compensation/invoices-urls

Retourne les URLs S3 pre-signees pour telecharger les factures PDF.

**Parametres :**
| Parametre | Type | Description |
|-----------|------|-------------|
| id_declaration | int | ID de la declaration (requis) |
| expires_in | int | Duree de validite en secondes (defaut: 3600) |

**Reponse :**
```json
{
  "success": true,
  "id_declaration": 1,
  "invoices_count": 2,
  "invoices": [
    {
      "id_invoice": 1,
      "original_filename": "facture_001.pdf",
      "s3_key": "declarations/704/1/invoices/facture_001.pdf",
      "file_size": 71382,
      "presigned_url": "https://bucket.s3.fr-par.scw.cloud/...",
      "expires_in": 3600
    }
  ]
}
```

#### GET/POST /api/dji-compensation/update-status

Met a jour le statut d'une declaration.

**Parametres :**
| Parametre | Type | Description |
|-----------|------|-------------|
| id_declaration | int | ID de la declaration (requis) |
| status | string | Nouveau statut : draft, submitted, processing, validated, rejected (requis) |
| admin_notes | string | Notes administrateur (optionnel) |

**Reponse :**
```json
{
  "success": true,
  "id_declaration": 1,
  "old_status": "submitted",
  "new_status": "validated",
  "message": "Status updated successfully"
}
```

#### GET /api/dji-compensation/declarations-summary

Retourne toutes les lignes declarees de toutes les declarations, avec infos client et promotion. Ideal pour export Google Sheets.

**Parametres :**
| Parametre | Type | Description |
|-----------|------|-------------|
| status | string | Filtrer par statut (submitted, processing, validated). Par defaut : tous |

**Reponse :**
```json
{
  "success": true,
  "total_declarations": 4,
  "total_lines": 10,
  "errors": [],
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
      "customer_siret": "12345678901234",
      "reference_article": "AR0059565",
      "produit": "Osmo Mobile 7P",
      "ean": "6941565997517",
      "quantite_declaree": 10,
      "prix_vente_ttc": 139,
      "prix_promo_ttc": 115,
      "compensation_ht": 14.4,
      "date_debut": "2026-01-01",
      "date_fin": "2026-01-31",
      "date_butoir": "2026-02-06"
    }
  ]
}
```

### Exemple d'utilisation avec n8n

#### Synchroniser les clients

1. Noeud **HTTP Request** : `GET /api/dji-compensation/customers`
2. Header : `Authorization: Bearer VOTRE_CLE_API`
3. Noeud **Database** : INSERT/UPSERT dans votre table de reference

#### Synchroniser le template promotions

Workflow suggere :
```
[Cron Trigger] --> [HTTP Request] --> [Database]
                   GET /template       UPSERT promotions
```

1. Noeud **HTTP Request** :
   - Method: GET
   - URL: `https://votresite.com/api/dji-compensation/template`
   - Header: `Authorization: Bearer VOTRE_CLE_API`

2. Noeud **Database** (PostgreSQL exemple) :
```sql
INSERT INTO dji_promotions (reference, produit, prix, remise)
VALUES ($1, $2, $3, $4)
ON CONFLICT (reference) DO UPDATE SET
  produit = EXCLUDED.produit,
  prix = EXCLUDED.prix,
  remise = EXCLUDED.remise,
  updated_at = NOW();
```

Schema suggere :
```sql
CREATE TABLE dji_promotions (
  id SERIAL PRIMARY KEY,
  reference VARCHAR(50) UNIQUE,
  produit VARCHAR(255),
  prix DECIMAL(10,2),
  remise VARCHAR(20),
  updated_at TIMESTAMP DEFAULT NOW()
);
```

#### Workflow d'analyse automatique des declarations

Le module supporte un workflow n8n complet pour analyser automatiquement les declarations soumises :

1. **Recuperation des declarations** : `GET /declarations-to-process`
2. **Extraction des lignes declarees** : `GET /declaration-xlsx`
3. **Analyse des factures PDF** : Via Mistral API (OCR)
4. **Verification des coherences** :
   - Quantites declarees vs facturees
   - Prix factures vs prix promotions (tolerance 5€)
5. **Mise a jour du statut** : `GET /update-status`

**Matching des produits** (ordre de priorite) :
1. Code EAN (13 chiffres)
2. Reference article
3. Designation partielle (2+ mots communs)

**Types de resultats** :
- `ok` : Ligne verifiee sans probleme
- `article_non_facture` : Article declare non trouve dans les factures
- `quantite_mismatch` : Ecart entre quantite declaree et facturee
- `prix_mismatch` : Ecart de prix > 5€ vs promotion

**Tables PostgreSQL requises** : voir `scripts-sql/create_analyse_tables.sql`

**Template workflow n8n** : voir `n8n_workflows/DJI Compensation - Analyse Declarations.json`

#### Export Google Sheets (agregation par promotion/client)

Workflow pour exporter les declarations agregees par promotion et client :

```
[Trigger] --> [HTTP Request] --> [Split Out] --> [Code] --> [Google Sheets]
              GET /declarations-summary          Agregation    Append/Update
```

1. **HTTP Request** : `GET /api/dji-compensation/declarations-summary`
2. **Split Out** : field `lines`
3. **Code** : Agregation par cle `(reference_article, date_debut, date_fin, id_customer)`
4. **Google Sheets** : Ecriture des totaux

**Cle d'agregation** : Une meme promotion est identifiee par `reference_article + date_debut + date_fin` (un produit peut avoir plusieurs promos sur des periodes differentes).

**Colonnes suggerees** :
| Reference | Produit | EAN | Date debut | Date fin | Client | SIRET | Qte declaree | Compensation HT | Nb declarations |
