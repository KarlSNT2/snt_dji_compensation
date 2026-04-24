-- ============================================================
-- Table : prestashop_customers
-- Source : API PrestaShop (module DJI Compensation)
-- Objectif : Table pivot pour lier clients, déclarations et factures
-- ============================================================

CREATE TABLE IF NOT EXISTS public.prestashop_customers (
    id_customer             INTEGER         PRIMARY KEY,

    -- Identité
    firstname               VARCHAR(100)    NOT NULL,
    lastname                VARCHAR(100)    NOT NULL,
    email                   VARCHAR(255)    NOT NULL UNIQUE,
    company                 VARCHAR(200),
    siret                   VARCHAR(14),

    -- Contexte PrestaShop
    customer_since          TIMESTAMPTZ,
    s3_path                 VARCHAR(255),
    declarations_count      INTEGER         DEFAULT 0,

    -- Métadonnées sync
    last_sync_at            TIMESTAMPTZ     NOT NULL DEFAULT NOW(),
    created_at              TIMESTAMPTZ     NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ     NOT NULL DEFAULT NOW()
);

-- ============================================================
-- Table : declarations
-- Cache local des déclarations PrestaShop
-- ============================================================

CREATE TABLE IF NOT EXISTS public.declarations (
    id_declaration          INTEGER         PRIMARY KEY,
    id_customer             INTEGER         NOT NULL
                                REFERENCES public.prestashop_customers(id_customer)
                                ON DELETE CASCADE,

    -- Données déclaration
    reference               VARCHAR(50)     NOT NULL UNIQUE,
    status                  VARCHAR(30)     NOT NULL,
    status_label            VARCHAR(50),
    has_mic_power_products  BOOLEAN         DEFAULT FALSE,
    xlsx_filename           VARCHAR(255),
    s3_path                 VARCHAR(255),

    -- Dates PrestaShop
    date_add                TIMESTAMPTZ,
    date_upd                TIMESTAMPTZ,

    -- Métadonnées sync
    last_sync_at            TIMESTAMPTZ     NOT NULL DEFAULT NOW(),
    created_at              TIMESTAMPTZ     NOT NULL DEFAULT NOW()
);

-- ============================================================
-- Ajout FK id_customer sur factures_revendeurs (si table existe)
-- ============================================================

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'factures_revendeurs'
        AND column_name = 'id_customer'
    ) THEN
        ALTER TABLE public.factures_revendeurs
            ADD COLUMN id_customer INTEGER
            REFERENCES public.prestashop_customers(id_customer)
            ON DELETE SET NULL;
    END IF;
END $$;

-- ============================================================
-- Index
-- ============================================================

-- Recherche client par SIRET (matching avec factures)
CREATE INDEX IF NOT EXISTS idx_customers_siret
    ON public.prestashop_customers (siret);

-- Recherche client par email
CREATE INDEX IF NOT EXISTS idx_customers_email
    ON public.prestashop_customers (email);

-- Recherche client par société
CREATE INDEX IF NOT EXISTS idx_customers_company
    ON public.prestashop_customers (company);

-- Déclarations par client
CREATE INDEX IF NOT EXISTS idx_declarations_customer
    ON public.declarations (id_customer);

-- Déclarations par statut (workflow validation)
CREATE INDEX IF NOT EXISTS idx_declarations_status
    ON public.declarations (status);

-- Déclarations par référence
CREATE INDEX IF NOT EXISTS idx_declarations_reference
    ON public.declarations (reference);

-- Factures par client (après ajout FK)
CREATE INDEX IF NOT EXISTS idx_factures_customer
    ON public.factures_revendeurs (id_customer);

-- ============================================================
-- Trigger updated_at
-- ============================================================

CREATE OR REPLACE TRIGGER trg_customers_updated_at
    BEFORE UPDATE ON public.prestashop_customers
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at();

-- ============================================================
-- Commentaires
-- ============================================================

COMMENT ON TABLE  public.prestashop_customers IS 'Clients PrestaShop synchronisés depuis l''API DJI Compensation';
COMMENT ON TABLE  public.declarations IS 'Déclarations de compensation DJI - cache local de l''API PrestaShop';

COMMENT ON COLUMN public.prestashop_customers.id_customer IS 'ID PrestaShop du client (clé primaire externe)';
COMMENT ON COLUMN public.prestashop_customers.siret IS 'SIRET 14 chiffres - utilisé pour matcher les factures revendeurs';
COMMENT ON COLUMN public.prestashop_customers.s3_path IS 'Chemin S3 racine des fichiers du client';
COMMENT ON COLUMN public.prestashop_customers.last_sync_at IS 'Dernière synchronisation depuis l''API PrestaShop';

COMMENT ON COLUMN public.declarations.reference IS 'Référence unique de déclaration (ex: DJI-A1B2C3D4)';
COMMENT ON COLUMN public.declarations.status IS 'draft | submitted | processing | validated | rejected';
COMMENT ON COLUMN public.declarations.has_mic_power_products IS 'Nécessite photos de stock si true';
COMMENT ON COLUMN public.declarations.s3_path IS 'Chemin S3 des fichiers de cette déclaration';
