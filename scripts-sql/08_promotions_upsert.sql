-- =============================================================================
-- Synchro des promotions par UPSERT (septembre 2026)
--
-- Le flux "Insert Actual Promotions" ne vide plus la table : il insere les
-- promos nouvelles et met a jour les existantes, identifiees par le trio
-- (reference_article, date_debut, date_fin). Une promo retiree du template
-- reste donc en base, pour qu'une declaration tardive la retrouve encore.
--
-- Ce script prepare la table a ce fonctionnement. Il est rejouable.
--
-- RAPPEL CLOUDBEAVER : ne pas laisser de ligne vide A L INTERIEUR de une
-- instruction, le option "Blank line is statement delimiter" la couperait.
-- Executer le script entier avec Alt+X, pas Ctrl+Entree.
-- =============================================================================
-- Tables creees avant la version actuelle de 01_create_table_promotions_dji.sql
ALTER TABLE public.promotions_dji
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

-- Certains libelles de type depassent 100 caracteres ("Remise par code promo -
-- non appliquee directement sur le prix de vente produit : ...").
ALTER TABLE public.promotions_dji
    ALTER COLUMN type_promotion TYPE TEXT;

-- =============================================================================
-- Dedoublonnage prealable a l'index unique.
--
-- Le template porte des lignes en double sur le trio (8 dans la version du
-- 25/09/2026), que l'ancien TRUNCATE + INSERT recopiait telles quelles. On garde
-- la derniere inseree (ctid le plus grand), c'est-a-dire la derniere du fichier :
-- c'est aussi celle que retient desormais le flux.
-- =============================================================================
DELETE FROM public.promotions_dji a
USING public.promotions_dji b
WHERE a.reference_article = b.reference_article
  AND a.date_debut = b.date_debut
  AND COALESCE(a.date_fin, '9999-12-31') = COALESCE(b.date_fin, '9999-12-31')
  AND a.ctid < b.ctid;

-- Cible du ON CONFLICT du flux. L'expression doit rester identique a celle de
-- la requete n8n : (reference_article, date_debut, (COALESCE(date_fin, '9999-12-31'::date))).
CREATE UNIQUE INDEX IF NOT EXISTS uq_promo_ref_dates
    ON public.promotions_dji (reference_article, date_debut, COALESCE(date_fin, '9999-12-31'));

-- Controle : doit renvoyer 0 ligne.
SELECT reference_article, date_debut, date_fin, count(*)
FROM public.promotions_dji
GROUP BY 1, 2, 3
HAVING count(*) > 1;
