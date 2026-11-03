-- =============================================================================
-- Table : declarations_export
-- Miroir en base de la feuille Google Sheets "DJI Compensations / Feuille 1".
-- Alimentee par le workflow n8n "Declarations summary", AVANT le append Sheets.
--
-- Objectif : donner une memoire persistante et requetable a le export, pour
--  - detecter les lignes redeclarees (regle metier : 1 declaration par promo)
--  - rejouer un export sans doublonner (cle de idempotence)
--  - tracer chaque ligne du Sheet jusque a sa declaration de origine
--
-- NOTE DE MISE EN OEUVRE
-- Aucune ligne vide ne doit apparaitre A L INTERIEUR de une instruction :
-- CloudBeaver et DBeaver activent par defaut le option
-- "Blank line is statement delimiter", qui couperait le CREATE TABLE en
-- morceaux et produirait une erreur de syntaxe.
-- Les apostrophes sont egalement evitees dans les commentaires par prudence.
-- =============================================================================
CREATE TABLE IF NOT EXISTS public.declarations_export (
    id                      SERIAL          PRIMARY KEY,
    -- --- Cles techniques (absentes du Sheet, indispensables pour tracer) -----
    id_declaration          INTEGER         NOT NULL,
    ligne_num               INTEGER         NOT NULL,
    id_customer             INTEGER         NOT NULL,
    declaration_reference   VARCHAR(50),
    -- --- Colonnes miroir du Google Sheet ------------------------------------
    nom_du_client           TEXT,
    type_de_promo           VARCHAR(100),
    ref_article             VARCHAR(50),
    designation             TEXT,
    date_debut              DATE,
    date_fin                DATE,
    quantite                INTEGER,
    compensation_ht         NUMERIC(10,2),
    total                   NUMERIC(10,2),
    ref_avoir               VARCHAR(100),
    date_declaration        TIMESTAMP,
    -- --- Drapeaux de anomalie -----------------------------------------------
    -- Le Sheet affiche un STATUT agrege ; la base garde le detail atomique.
    is_retard               BOOLEAN         NOT NULL DEFAULT FALSE,
    is_doublon              BOOLEAN         NOT NULL DEFAULT FALSE,
    -- Reference de la declaration qui avait deja couvert cette promo
    doublon_de_declaration  INTEGER,
    doublon_de_reference    VARCHAR(50),
    doublon_de_date         TIMESTAMP,
    -- --- Suivi commercial (les doublons ne bloquent pas, ils se traitent) ----
    traite                  BOOLEAN         NOT NULL DEFAULT FALSE,
    traite_par              VARCHAR(100),
    traite_at               TIMESTAMPTZ,
    commentaire             TEXT,
    -- --- Meta ---------------------------------------------------------------
    exported_at             TIMESTAMPTZ     NOT NULL DEFAULT NOW()
);

-- =============================================================================
-- Cle de idempotence
-- Une ligne = (declaration, position de la ligne dans son XLSX).
-- Le XLSX est fige des la soumission cote module : isXlsxEditable() ne permet
-- la modification que en brouillon, donc ce couple est stable dans le temps.
-- Rejouer le workflow ne cree jamais de doublon technique.
-- =============================================================================
CREATE UNIQUE INDEX IF NOT EXISTS uq_declarations_export_ligne
    ON public.declarations_export (id_declaration, ligne_num);

-- Lookup de detection de doublon metier (client + promo)
CREATE INDEX IF NOT EXISTS idx_declarations_export_promo
    ON public.declarations_export (id_customer, ref_article, date_fin);

CREATE INDEX IF NOT EXISTS idx_declarations_export_declaration
    ON public.declarations_export (id_declaration);

CREATE INDEX IF NOT EXISTS idx_declarations_export_doublon
    ON public.declarations_export (is_doublon) WHERE is_doublon;

CREATE INDEX IF NOT EXISTS idx_declarations_export_retard
    ON public.declarations_export (is_retard) WHERE is_retard;

COMMENT ON TABLE public.declarations_export IS
    'Miroir de la feuille Google Sheets de recap ; une ligne par ligne exportee';

COMMENT ON COLUMN public.declarations_export.ligne_num IS
    'Position de la ligne dans le XLSX de la declaration (1-based), cle de idempotence';

COMMENT ON COLUMN public.declarations_export.is_doublon IS
    'Le couple (client, ref_article, date_fin) etait deja couvert par une declaration anterieure';

