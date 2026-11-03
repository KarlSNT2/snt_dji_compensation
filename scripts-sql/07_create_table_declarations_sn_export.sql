-- =============================================================================
-- Table : declarations_sn_export
-- Miroir en base du second classeur Google Sheets, celui des numeros de serie.
-- Alimentee par le workflow n8n "Declarations SN summary", AVANT le append.
--
-- Pendant de declarations_export, mais sur la feuille "Matrice declaration SN"
-- du fichier client : une ligne par numero de serie, la ou la premiere feuille
-- raisonne en quantites. Les deux exports sont independants ; une declaration
-- peut alimenter les deux, un seul, ou aucun.
--
-- NOTE DE MISE EN OEUVRE
-- Aucune ligne vide ne doit apparaitre A L INTERIEUR de une instruction :
-- CloudBeaver et DBeaver activent par defaut le option
-- "Blank line is statement delimiter", qui couperait le CREATE TABLE en
-- morceaux et produirait une erreur de syntaxe.
-- Executer le script entier avec Alt+X, pas Ctrl+Entree.
-- =============================================================================
CREATE TABLE IF NOT EXISTS public.declarations_sn_export (
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
    ean                     VARCHAR(30),
    numero_serie            TEXT            NOT NULL,
    date_debut              DATE,
    date_fin                DATE,
    quantite                INTEGER,
    compensation_ht         NUMERIC(10,2),
    total                   NUMERIC(10,2),
    date_declaration        TIMESTAMP,
    -- --- Controle de prix, hors Sheet mais utile aux verifications ----------
    prix_vente_ttc          NUMERIC(10,2),
    nouveau_prix_ttc        NUMERIC(10,2),
    -- --- Drapeaux de anomalie -----------------------------------------------
    is_retard               BOOLEAN         NOT NULL DEFAULT FALSE,
    is_doublon              BOOLEAN         NOT NULL DEFAULT FALSE,
    -- Ligne qui avait deja declare ce numero de serie
    doublon_de_declaration  INTEGER,
    doublon_de_reference    VARCHAR(50),
    doublon_de_date         TIMESTAMP,
    doublon_de_client       TEXT,
    -- --- Suivi commercial (les doublons ne bloquent pas, ils se traitent) ----
    traite                  BOOLEAN         NOT NULL DEFAULT FALSE,
    traite_par              VARCHAR(100),
    traite_at               TIMESTAMPTZ,
    commentaire             TEXT,
    -- --- Archivage mensuel ---------------------------------------------------
    archived_at             TIMESTAMPTZ,
    archive_period          DATE,
    archive_sheet_name      VARCHAR(150),
    -- --- Meta ---------------------------------------------------------------
    exported_at             TIMESTAMPTZ     NOT NULL DEFAULT NOW()
);

-- =============================================================================
-- Numero de serie normalise, pour la detection de doublon.
-- Les revendeurs saisissent a la main : casse et espaces varient. On compare
-- sur une forme normalisee tout en gardant la saisie brute pour le Sheet.
-- upper() et btrim() sont IMMUTABLE, la colonne generee est donc acceptee.
-- =============================================================================
ALTER TABLE public.declarations_sn_export
    ADD COLUMN IF NOT EXISTS numero_serie_norm TEXT
    GENERATED ALWAYS AS (upper(btrim(numero_serie))) STORED;

-- =============================================================================
-- Mois de rattachement pour le archivage : mois de date_fin, ou a defaut mois
-- de la declaration pour les baisses de prix sans date de fin.
-- Le cast ::timestamp est OBLIGATOIRE. Sans lui, date_trunc(text, date) est
-- resolu vers la surcharge timestamptz, qui est STABLE et non IMMUTABLE :
-- Postgres rejette alors la colonne generee (SQLSTATE 42P17).
-- =============================================================================
ALTER TABLE public.declarations_sn_export
    ADD COLUMN IF NOT EXISTS periode DATE
    GENERATED ALWAYS AS (
        date_trunc('month', COALESCE(date_fin, date_declaration::date)::timestamp)::date
    ) STORED;

-- =============================================================================
-- Cle de idempotence
-- Une ligne = (declaration, position de la ligne dans la feuille SN).
-- Le XLSX est fige des la soumission cote module : isXlsxEditable() ne permet
-- la modification que en brouillon, donc ce couple est stable dans le temps.
-- Rejouer le workflow ne cree jamais de doublon technique.
-- =============================================================================
CREATE UNIQUE INDEX IF NOT EXISTS uq_declarations_sn_export_ligne
    ON public.declarations_sn_export (id_declaration, ligne_num);

-- =============================================================================
-- Lookup de detection de doublon metier.
-- Ici la regle differe de celle de declarations_export : DJI compense AU
-- NUMERO DE SERIE. Un meme SN declare deux fois est une double demande de
-- compensation, que ce soit dans le meme fichier, par le meme revendeur a deux
-- reprises, ou par deux revendeurs differents. Les trois cas doivent ressortir,
-- la comparaison ne se restreint donc ni au client ni a la declaration.
-- =============================================================================
CREATE INDEX IF NOT EXISTS idx_declarations_sn_export_serie
    ON public.declarations_sn_export (numero_serie_norm);

CREATE INDEX IF NOT EXISTS idx_declarations_sn_export_declaration
    ON public.declarations_sn_export (id_declaration);

CREATE INDEX IF NOT EXISTS idx_declarations_sn_export_doublon
    ON public.declarations_sn_export (is_doublon) WHERE is_doublon;

CREATE INDEX IF NOT EXISTS idx_declarations_sn_export_retard
    ON public.declarations_sn_export (is_retard) WHERE is_retard;

-- Le flux de archivage ne lit que les lignes non archivees : index partiel.
CREATE INDEX IF NOT EXISTS idx_declarations_sn_export_a_archiver
    ON public.declarations_sn_export (periode)
    WHERE archived_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_declarations_sn_export_archive_period
    ON public.declarations_sn_export (archive_period);

COMMENT ON TABLE public.declarations_sn_export IS
    'Miroir du classeur Google Sheets des numeros de serie ; une ligne par SN declare';

COMMENT ON COLUMN public.declarations_sn_export.ligne_num IS
    'Position de la ligne dans la feuille SN de la declaration (1-based), cle de idempotence';

COMMENT ON COLUMN public.declarations_sn_export.numero_serie_norm IS
    'Numero de serie normalise (majuscules, sans espaces autour) servant a la detection de doublon';

COMMENT ON COLUMN public.declarations_sn_export.is_doublon IS
    'Ce numero de serie avait deja ete declare, par ce client ou par un autre';
