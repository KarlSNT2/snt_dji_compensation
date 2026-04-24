-- ============================================================
-- Table : factures_revendeurs
-- Source : Factures JSON envoyées par les revendeurs
-- Objectif : Vérification des prix revendeurs vs promotions DJI
-- ============================================================

CREATE TABLE IF NOT EXISTS public.factures_revendeurs (
    id                      SERIAL          PRIMARY KEY,

    -- En-tête facture
    facture_numero          VARCHAR(100)    NOT NULL,
    facture_date            DATE            NOT NULL,

    -- Vendeur
    vendeur_nom             VARCHAR(200)    NOT NULL,
    vendeur_siret           VARCHAR(14),
    vendeur_tva             VARCHAR(20),

    -- Client
    client_nom              VARCHAR(200),
    client_adresse          TEXT,

    -- Totaux
    total_ht                NUMERIC(10,2),
    total_tva               NUMERIC(10,2),
    total_ttc               NUMERIC(10,2),
    reste_a_payer           NUMERIC(10,2),
    mode_paiement           VARCHAR(50),

    -- Métadonnées
    created_at              TIMESTAMPTZ     NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ     NOT NULL DEFAULT NOW(),

    -- Contrainte d'unicité : une même facture d'un même vendeur ne peut pas être insérée deux fois
    CONSTRAINT uq_facture_vendeur UNIQUE (facture_numero, vendeur_siret)
);

-- ============================================================
-- Table : factures_revendeurs_lignes
-- Lignes articles de chaque facture (relation 1-N)
-- ============================================================

CREATE TABLE IF NOT EXISTS public.factures_revendeurs_lignes (
    id                      SERIAL          PRIMARY KEY,
    facture_id              INTEGER         NOT NULL
                                REFERENCES public.factures_revendeurs(id)
                                ON DELETE CASCADE,

    -- Données brutes de la ligne
    designation             TEXT            NOT NULL,
    ean                     VARCHAR(30),
    numero_serie            VARCHAR(30),
    code_article            VARCHAR(50),
    quantite                NUMERIC(10,3)   NOT NULL,   -- NUMERIC pour gérer les quantités négatives (avoirs)
    prix_ht                 NUMERIC(10,2),
    prix_ttc                NUMERIC(10,2),

    -- Lien vers promotions_dji (NULL si la ligne ne correspond à aucune promo connue)
    -- Rempli par le workflow de matching — pas de FK car plusieurs promos peuvent avoir le même reference_article
    promo_reference_article VARCHAR(50),

    -- Résultat du contrôle de prix (rempli après matching)
    controle_statut         VARCHAR(30),    -- 'OK', 'ECART_PRIX', 'PROMO_INCONNUE', 'HORS_PERIODE', 'AVOIR'
    controle_ecart_ttc      NUMERIC(10,2),  -- prix_ttc ligne - nouveau_prix_ttc promo (si match trouvé)
    controle_date           TIMESTAMPTZ,

    created_at              TIMESTAMPTZ     NOT NULL DEFAULT NOW()
);

-- ============================================================
-- Index
-- ============================================================

-- Recherche par numéro de facture
CREATE INDEX IF NOT EXISTS idx_factures_numero
    ON public.factures_revendeurs (facture_numero);

-- Recherche par vendeur
CREATE INDEX IF NOT EXISTS idx_factures_vendeur_siret
    ON public.factures_revendeurs (vendeur_siret);

-- Recherche par date de facture (analyses périodiques)
CREATE INDEX IF NOT EXISTS idx_factures_date
    ON public.factures_revendeurs (facture_date);

-- Jointure lignes → facture
CREATE INDEX IF NOT EXISTS idx_lignes_facture_id
    ON public.factures_revendeurs_lignes (facture_id);

-- Jointure lignes → promotions (matching)
CREATE INDEX IF NOT EXISTS idx_lignes_promo_reference
    ON public.factures_revendeurs_lignes (promo_reference_article);

-- Filtrage par statut de contrôle (tableau de bord anomalies)
CREATE INDEX IF NOT EXISTS idx_lignes_controle_statut
    ON public.factures_revendeurs_lignes (controle_statut);

-- ============================================================
-- Trigger updated_at sur factures_revendeurs
-- ============================================================

-- Réutilisation de la fonction update_updated_at() déjà créée dans 01_...
-- Si ce script est exécuté seul, décommenter la fonction ci-dessous :
--
-- CREATE OR REPLACE FUNCTION update_updated_at()
-- RETURNS TRIGGER AS $$
-- BEGIN
--     NEW.updated_at = NOW();
--     RETURN NEW;
-- END;
-- $$ LANGUAGE plpgsql;

CREATE OR REPLACE TRIGGER trg_factures_updated_at
    BEFORE UPDATE ON public.factures_revendeurs
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at();

-- ============================================================
-- Commentaires
-- ============================================================

COMMENT ON TABLE  public.factures_revendeurs                        IS 'Factures reçues des revendeurs — en-tête et totaux';
COMMENT ON TABLE  public.factures_revendeurs_lignes                  IS 'Lignes articles des factures revendeurs';

COMMENT ON COLUMN public.factures_revendeurs.vendeur_siret           IS 'SIRET sur 14 chiffres — identifiant unique du revendeur';
COMMENT ON COLUMN public.factures_revendeurs.vendeur_tva             IS 'Numéro de TVA intracommunautaire (ex: FR23841695323)';

COMMENT ON COLUMN public.factures_revendeurs_lignes.quantite         IS 'Peut être négatif (ligne avoir / remise opération DJI)';
COMMENT ON COLUMN public.factures_revendeurs_lignes.ean              IS 'Code EAN de la ligne article — peut être absent selon le format de facture';
COMMENT ON COLUMN public.factures_revendeurs_lignes.numero_serie     IS 'Numéro de série de la ligne article — peut être absent selon le format de facture';
COMMENT ON COLUMN public.factures_revendeurs_lignes.code_article     IS 'Code article de la ligne article — peut être absent selon le format de facture';
COMMENT ON COLUMN public.factures_revendeurs_lignes.promo_reference_article IS 'Référence DJI matchée dans promotions_dji — NULL si non identifiée';
COMMENT ON COLUMN public.factures_revendeurs_lignes.controle_statut  IS 'OK | ECART_PRIX | PROMO_INCONNUE | HORS_PERIODE | AVOIR';
COMMENT ON COLUMN public.factures_revendeurs_lignes.controle_ecart_ttc IS 'Différence prix_ttc facture - nouveau_prix_ttc promo (positif = revendeur a surfacturé)';