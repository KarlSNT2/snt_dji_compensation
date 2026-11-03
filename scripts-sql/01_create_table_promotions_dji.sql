-- ============================================================
-- Table : promotions_dji
-- Source : Feuille "Promotions et Baisses de prix"
-- Fichier : Promotions_DJI.xlsx
-- ============================================================

CREATE TABLE IF NOT EXISTS public.promotions_dji (
    id                        SERIAL        PRIMARY KEY,
    statut                    VARCHAR(50),
    type_promotion            TEXT,
    produit                   TEXT          NOT NULL,
    ean                       VARCHAR(30),
    reference_article         VARCHAR(50)   NOT NULL,
    date_debut                DATE          NOT NULL,
    date_fin                  DATE,
    prix_vente_ttc            NUMERIC(10,2),
    nouveau_prix_ttc          NUMERIC(10,2),
    difference                NUMERIC(10,2),
    compensation_ht           NUMERIC(10,2),
    date_butoir_declaration   DATE,
    mise_a_jour               DATE,
    declaration_sts_dji       VARCHAR(100),
    created_at                TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at                TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

-- ============================================================
-- Index utiles pour les requêtes courantes
-- ============================================================

-- Recherche par référence article (identifiant produit DJI)
CREATE INDEX IF NOT EXISTS idx_promotions_dji_reference
    ON public.promotions_dji (reference_article);

-- Filtrage par statut et type
CREATE INDEX IF NOT EXISTS idx_promotions_dji_statut
    ON public.promotions_dji (statut);

CREATE INDEX IF NOT EXISTS idx_promotions_dji_type
    ON public.promotions_dji (type_promotion);

-- Filtrage par période
CREATE INDEX IF NOT EXISTS idx_promotions_dji_dates
    ON public.promotions_dji (date_debut, date_fin);

-- Contrainte d'unicité (référence, date_début, date_fin)
-- COALESCE transforme NULL en sentinelle pour que l'unicité fonctionne
-- aussi sur les baisses de prix définitives (sans date de fin).
CREATE UNIQUE INDEX IF NOT EXISTS uq_promo_ref_dates
    ON public.promotions_dji (reference_article, date_debut, COALESCE(date_fin, '9999-12-31'));

-- ============================================================
-- Trigger pour mettre à jour updated_at automatiquement
-- ============================================================

CREATE OR REPLACE FUNCTION update_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE TRIGGER trg_promotions_dji_updated_at
    BEFORE UPDATE ON public.promotions_dji
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at();

-- ============================================================
-- Commentaires de colonnes
-- ============================================================

COMMENT ON TABLE  public.promotions_dji                    IS 'Promotions et baisses de prix DJI importées depuis Excel';
COMMENT ON COLUMN public.promotions_dji.ean                IS 'Code EAN — peut être absent si #VALUE! dans le fichier source';
COMMENT ON COLUMN public.promotions_dji.compensation_ht    IS 'Montant de compensation HT versé par DJI au revendeur';
COMMENT ON COLUMN public.promotions_dji.declaration_sts_dji IS 'Statut de la déclaration STS auprès de DJI';