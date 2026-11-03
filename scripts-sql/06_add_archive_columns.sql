-- =============================================================================
-- Archivage mensuel du recap Google Sheets
--
-- Le flux cron du 8 de chaque mois deplace les lignes des promos terminees vers
-- un onglet "Mois Annee - DJI Compensations" du meme classeur, puis reecrit
-- Feuille 1 avec ce qui reste.
--
-- La periode de rattachement est le mois de date_fin. Pour les baisses de prix
-- definitives (date_fin NULL), on retombe sur le mois de la declaration.
--
-- RAPPEL CLOUDBEAVER : ne pas laisser de ligne vide A L INTERIEUR de une
-- instruction, le option "Blank line is statement delimiter" la couperait.
-- Executer le script entier avec Alt+X, pas Ctrl+Entree.
-- =============================================================================
ALTER TABLE public.declarations_export
    ADD COLUMN IF NOT EXISTS archived_at        TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS archive_period     DATE,
    ADD COLUMN IF NOT EXISTS archive_sheet_name VARCHAR(150);

COMMENT ON COLUMN public.declarations_export.archived_at IS
    'Horodatage du transfert vers le onglet mensuel ; NULL = encore dans Feuille 1';

COMMENT ON COLUMN public.declarations_export.archive_period IS
    'Premier jour du mois archive, par exemple 2026-09-01';

COMMENT ON COLUMN public.declarations_export.archive_sheet_name IS
    'Nom de le onglet Google Sheets qui contient la ligne apres archivage';

-- =============================================================================
-- Colonne generee : le mois de rattachement, calcule une fois pour toutes.
-- Le cast ::timestamp est OBLIGATOIRE. Sans lui, date_trunc(text, date) est
-- resolu vers la surcharge timestamptz, qui est STABLE et non IMMUTABLE :
-- Postgres rejette alors la colonne generee (SQLSTATE 42P17).
-- Elle evite de repeter le COALESCE dans chaque requete du flux et permet de
-- indexer le filtre reellement utilise.
-- =============================================================================
ALTER TABLE public.declarations_export
    ADD COLUMN IF NOT EXISTS periode DATE
    GENERATED ALWAYS AS (
        date_trunc('month', COALESCE(date_fin, date_declaration::date)::timestamp)::date
    ) STORED;

COMMENT ON COLUMN public.declarations_export.periode IS
    'Mois de rattachement : mois de date_fin, ou mois de declaration si pas de date de fin';

-- Le flux ne lit que les lignes non archivees : index partiel sur ce seul cas.
CREATE INDEX IF NOT EXISTS idx_declarations_export_a_archiver
    ON public.declarations_export (periode)
    WHERE archived_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_declarations_export_archive_period
    ON public.declarations_export (archive_period);
