# -*- coding: utf-8 -*-
"""
Tests de la synchro promotions_dji par UPSERT (flux "Insert Actual Promotions").

Verifie :
- la migration 08 (dedoublonnage puis index unique), rejouable ;
- l'upsert : insertion, ligne inchangee non reecrite, mise a jour, promo
  retiree du template conservee, date_fin NULL (baisse de prix definitive),
  libelle de type de plus de 100 caracteres.

La requete testee n'est PAS recopiee ici : elle est lue directement dans le node
"Upsert promotions_dji" du workflow n8n, qui reste la source unique de verite.

Usage:
    pip install "psycopg[binary]"
    docker run -d --name djitest -e POSTGRES_PASSWORD=test -e POSTGRES_DB=app_db \
        -p 55432:5432 postgres:16-alpine
    python scripts-sql/tests/test_promotions_upsert.py

Variable d'environnement optionnelle :
    PGDSN (defaut: postgresql://postgres:test@localhost:55432/app_db)
"""
import io
import json
import os
import re
import sys

import psycopg

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
DDL = os.path.join(ROOT, "scripts-sql", "01_create_table_promotions_dji.sql")
MIGRATION = os.path.join(ROOT, "scripts-sql", "08_promotions_upsert.sql")
WORKFLOW = os.path.join(ROOT, "n8n_workflows", "Insert Actual Promotions.json")
DSN = os.environ.get("PGDSN", "postgresql://postgres:test@localhost:55432/app_db")


def load_query():
    wf = json.load(io.open(WORKFLOW, encoding="utf-8"))
    node = next(n for n in wf["nodes"] if n["name"] == "Upsert promotions_dji")
    repl = node["parameters"]["options"]["queryReplacement"]
    assert repl == "={{ [$json.payload] }}", "parametre inattendu : %s" % repl
    query = node["parameters"]["query"]
    assert re.findall(r"\$\d+", query) == ["$1"], "un seul parametre $1 attendu"
    return query.replace("$1", "%s")


def promo(ref, debut, fin, **kw):
    row = {
        "statut": "Confirmé", "type_promotion": "1. Promotion", "produit": "Produit " + ref,
        "ean": "123", "reference_article": ref, "date_debut": debut, "date_fin": fin,
        "prix_vente_ttc": 100, "nouveau_prix_ttc": 90, "difference": 10,
        "compensation_ht": 6, "date_butoir_declaration": None, "mise_a_jour": None,
        "declaration_sts_dji": None,
    }
    row.update(kw)
    return row


def run(cur, query, rows):
    cur.execute(query, [json.dumps(rows)])
    return dict(zip([d.name for d in cur.description], cur.fetchone()))


def count(cur):
    return cur.execute("SELECT count(*) FROM promotions_dji").fetchone()[0]


def main():
    query = load_query()
    failures = 0

    def check(label, cond, detail=""):
        nonlocal failures
        print(("  OK   " if cond else "  FAIL ") + label + ("" if cond else "  -> %s" % detail))
        if not cond:
            failures += 1

    with psycopg.connect(DSN, autocommit=True) as conn:
        cur = conn.cursor()
        cur.execute("DROP TABLE IF EXISTS public.promotions_dji CASCADE")
        cur.execute(io.open(DDL, encoding="utf-8").read())

        print("Migration 08 sur une table remplie par l'ancien TRUNCATE + INSERT")
        # L'ancien flux inserait les doublons du fichier : on reproduit cet etat.
        cur.execute("DROP INDEX uq_promo_ref_dates")
        cur.execute("""
            INSERT INTO promotions_dji (produit, reference_article, date_debut, date_fin, compensation_ht)
            VALUES ('A', 'AR1', '2026-09-01', '2026-09-30', 1),
                   ('A', 'AR1', '2026-09-01', '2026-09-30', 2),
                   ('B', 'AR2', '2026-09-01', NULL, 5),
                   ('B', 'AR2', '2026-09-01', NULL, 6)
        """)
        migration = io.open(MIGRATION, encoding="utf-8").read()
        cur.execute(migration)
        cur.execute(migration)  # rejouable
        check("doublons supprimes", count(cur) == 2, count(cur))
        kept = cur.execute(
            "SELECT reference_article, compensation_ht FROM promotions_dji ORDER BY 1"
        ).fetchall()
        check("la derniere ligne inseree est conservee",
              [(r, float(c)) for r, c in kept] == [("AR1", 2.0), ("AR2", 6.0)], kept)
        check("index unique present", cur.execute(
            "SELECT 1 FROM pg_indexes WHERE indexname = 'uq_promo_ref_dates'").fetchone() is not None)

        print("Upsert")
        cur.execute("TRUNCATE promotions_dji")
        long_type = ("Remise par code promo - non appliquée directement sur le prix de vente "
                     "produit : déclaration avec numéro de série")
        v1 = [
            promo("AR1", "2026-09-01", "2026-09-30"),
            promo("AR2", "2026-09-01", None, type_promotion="3. Baisse de Prix"),
            promo("AR3", "2025-09-05", "2025-09-28", type_promotion=long_type),
        ]
        r = run(cur, query, v1)
        check("premier passage : 3 inserees", r == {"lignes_envoyees": 3, "inserees": 3,
                                                   "mises_a_jour": 0, "inchangees": 0}, r)

        before = cur.execute(
            "SELECT updated_at FROM promotions_dji WHERE reference_article = 'AR1'").fetchone()[0]
        r = run(cur, query, v1)
        check("meme fichier : rien de reecrit", r == {"lignes_envoyees": 3, "inserees": 0,
                                                     "mises_a_jour": 0, "inchangees": 3}, r)
        after = cur.execute(
            "SELECT updated_at FROM promotions_dji WHERE reference_article = 'AR1'").fetchone()[0]
        check("updated_at inchange sur une ligne identique", before == after)

        # Nouveau template : AR3 (ancienne promo) disparait du fichier, AR1 change
        # de compensation, AR2 (date_fin NULL) est reconduite, AR4 arrive.
        v2 = [
            promo("AR1", "2026-09-01", "2026-09-30", compensation_ht=8),
            promo("AR2", "2026-09-01", None, type_promotion="3. Baisse de Prix"),
            promo("AR4", "2026-09-15", "2026-09-30"),
        ]
        r = run(cur, query, v2)
        check("nouveau template : 1 inseree, 1 maj, 1 inchangee",
              r == {"lignes_envoyees": 3, "inserees": 1, "mises_a_jour": 1, "inchangees": 1}, r)
        check("promo retiree du template conservee", cur.execute(
            "SELECT 1 FROM promotions_dji WHERE reference_article = 'AR3'").fetchone() is not None)
        check("date_fin NULL : pas de doublon", cur.execute(
            "SELECT count(*) FROM promotions_dji WHERE reference_article = 'AR2'").fetchone()[0] == 1)
        comp = cur.execute(
            "SELECT compensation_ht FROM promotions_dji WHERE reference_article = 'AR1'").fetchone()[0]
        check("compensation mise a jour", float(comp) == 8.0, comp)
        check("total en base : 4 promos", count(cur) == 4, count(cur))

        print("Nouvelle periode sur une reference existante = nouvelle promo")
        r = run(cur, query, [promo("AR1", "2026-10-01", "2026-10-31")])
        check("inseree a cote de l'ancienne", r["inserees"] == 1 and cur.execute(
            "SELECT count(*) FROM promotions_dji WHERE reference_article = 'AR1'").fetchone()[0] == 2, r)

    print("\n%s" % ("TOUS LES TESTS PASSENT" if not failures else "%d ECHEC(S)" % failures))
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
