-- =============================================================================
-- Tables pour le workflow d'analyse des declarations DJI
-- A executer sur la base PostgreSQL n8n (app_db)
-- Date: 24 avril 2026
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Table: declaration_lignes
-- Lignes declarees par le client (parsees du XLSX)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS declaration_lignes (
    id SERIAL PRIMARY KEY,
    id_declaration INT NOT NULL,
    id_customer INT NOT NULL,
    reference_article VARCHAR(50),
    produit TEXT,
    ean VARCHAR(30),
    quantite_declaree INT,
    prix_vente_ttc NUMERIC(10,2),
    prix_promo_ttc NUMERIC(10,2),
    compensation_ht NUMERIC(10,2),
    montant_avoir NUMERIC(10,2),
    date_debut DATE,
    date_fin DATE,
    date_butoir DATE,
    numero_serie TEXT,
    reference_avoir VARCHAR(100),
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_decl_lignes_declaration ON declaration_lignes(id_declaration);
CREATE INDEX IF NOT EXISTS idx_decl_lignes_customer ON declaration_lignes(id_customer);
CREATE INDEX IF NOT EXISTS idx_decl_lignes_reference ON declaration_lignes(reference_article);

COMMENT ON TABLE declaration_lignes IS 'Lignes declarees par les clients, extraites de leur fichier XLSX';

-- -----------------------------------------------------------------------------
-- Table: factures_analysees
-- Resultats d analyse des factures PDF via Mistral API
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS factures_analysees (
    id SERIAL PRIMARY KEY,
    id_invoice INT NOT NULL,
    id_declaration INT NOT NULL,
    s3_key VARCHAR(512),
    numero_facture VARCHAR(50),
    date_facture DATE,
    total_ttc NUMERIC(10,2),
    raw_response JSONB,
    analyzed_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_factures_analysees_declaration ON factures_analysees(id_declaration);
CREATE INDEX IF NOT EXISTS idx_factures_analysees_invoice ON factures_analysees(id_invoice);

COMMENT ON TABLE factures_analysees IS 'Metadonnees des factures PDF analysees par Mistral API';

-- -----------------------------------------------------------------------------
-- Table: factures_produits
-- Produits extraits des factures (lignes de facture)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS factures_produits (
    id SERIAL PRIMARY KEY,
    id_facture_analysee INT NOT NULL REFERENCES factures_analysees(id) ON DELETE CASCADE,
    reference_article VARCHAR(50),
    designation TEXT,
    quantite INT,
    prix_unitaire_ttc NUMERIC(10,2),
    prix_ligne_ttc NUMERIC(10,2)
);

CREATE INDEX IF NOT EXISTS idx_factures_produits_facture ON factures_produits(id_facture_analysee);
CREATE INDEX IF NOT EXISTS idx_factures_produits_reference ON factures_produits(reference_article);

COMMENT ON TABLE factures_produits IS 'Lignes de produits extraites des factures par OCR Mistral';

-- -----------------------------------------------------------------------------
-- Table: verifications_anomalies
-- Anomalies detectees lors de la verification
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS verifications_anomalies (
    id SERIAL PRIMARY KEY,
    id_declaration INT NOT NULL,
    reference_article VARCHAR(50),
    type_anomalie VARCHAR(50) NOT NULL,
    quantite_declaree INT,
    quantite_facturee INT,
    prix_declare NUMERIC(10,2),
    prix_facture NUMERIC(10,2),
    prix_promo NUMERIC(10,2),
    ecart_prix NUMERIC(10,2),
    message TEXT,
    resolved BOOLEAN DEFAULT FALSE,
    resolved_at TIMESTAMPTZ,
    resolved_by VARCHAR(100),
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_anomalies_declaration ON verifications_anomalies(id_declaration);
CREATE INDEX IF NOT EXISTS idx_anomalies_type ON verifications_anomalies(type_anomalie);
CREATE INDEX IF NOT EXISTS idx_anomalies_resolved ON verifications_anomalies(resolved);

COMMENT ON TABLE verifications_anomalies IS 'Anomalies detectees: quantite_mismatch, prix_mismatch, article_inconnu, article_non_facture';

-- -----------------------------------------------------------------------------
-- Vue: v_declarations_a_verifier
-- Declarations avec le nombre d anomalies
-- -----------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_declarations_a_verifier AS
SELECT
    dl.id_declaration,
    dl.id_customer,
    COUNT(DISTINCT dl.id) AS nb_lignes_declarees,
    COUNT(DISTINCT fa.id) AS nb_factures_analysees,
    COUNT(DISTINCT va.id) AS nb_anomalies,
    COUNT(DISTINCT va.id) FILTER (WHERE va.resolved = FALSE) AS nb_anomalies_non_resolues,
    MIN(dl.created_at) AS premiere_analyse,
    MAX(fa.analyzed_at) AS derniere_analyse
FROM declaration_lignes dl
LEFT JOIN factures_analysees fa ON fa.id_declaration = dl.id_declaration
LEFT JOIN verifications_anomalies va ON va.id_declaration = dl.id_declaration
GROUP BY dl.id_declaration, dl.id_customer;

COMMENT ON VIEW v_declarations_a_verifier IS 'Resume des declarations avec statistiques d analyse';

-- -----------------------------------------------------------------------------
-- Vue: v_anomalies_par_type
-- Statistiques des anomalies par type
-- -----------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_anomalies_par_type AS
SELECT
    type_anomalie,
    COUNT(*) AS total,
    COUNT(*) FILTER (WHERE resolved = FALSE) AS non_resolues,
    COUNT(*) FILTER (WHERE resolved = TRUE) AS resolues
FROM verifications_anomalies
GROUP BY type_anomalie
ORDER BY total DESC;

COMMENT ON VIEW v_anomalies_par_type IS 'Statistiques des anomalies par type';
