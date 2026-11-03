# -*- coding: utf-8 -*-
"""
Tests de la regle metier de detection des doublons / retards.

La requete testee n'est PAS recopiee ici : elle est lue directement dans le node
"Insert Export Lines" du workflow n8n, qui reste la source unique de verite.
Modifier le workflow et relancer ces tests suffit a verifier la regle.

Usage:
    pip install "psycopg[binary]"
    docker run -d --name djitest -e POSTGRES_PASSWORD=test -e POSTGRES_DB=app_db \
        -p 55432:5432 postgres:16-alpine
    python scripts-sql/tests/test_declarations_export.py

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
DDL = os.path.join(ROOT, "scripts-sql", "05_create_table_declarations_export.sql")
WORKFLOW = os.path.join(ROOT, "n8n_workflows", "Declarations summary.json")
DSN = os.environ.get("PGDSN", "postgresql://postgres:test@localhost:55432/app_db")

# Ordre des parametres $1..$15, identique a queryReplacement dans le node n8n.
FIELDS = [
    "id_declaration", "ligne_num", "id_customer", "declaration_reference",
    "nom_du_client", "type_de_promo", "ref_article", "designation",
    "date_debut", "date_fin", "quantite", "compensation_ht", "total",
    "ref_avoir", "date_declaration",
]


def load_query():
    wf = json.load(io.open(WORKFLOW, encoding="utf-8"))
    node = next(n for n in wf["nodes"] if n["name"] == "Insert Export Lines")
    # Garde-fou : si l'ordre des parametres change dans le workflow, ces tests
    # deviendraient silencieusement faux. On le verifie.
    repl = node["parameters"]["options"]["queryReplacement"]
    # Forme tableau : une seule expression renvoyant un array. Voir le piege
    # "Variable $15 out of range" dans docs/PROCESS-DECLARATIONS.md.
    expected = "={{ [" + ", ".join("$json." + f for f in FIELDS) + "] }}"
    assert repl == expected, (
        "L'ordre des parametres du node a change.\n  attendu : %s\n  trouve  : %s"
        % (expected, repl)
    )
    return to_psycopg(node["parameters"]["query"])


def to_psycopg(query):
    """Traduit les parametres n8n en placeholders psycopg.

    n8n numerote ses parametres $1..$n ; psycopg attend des %s positionnels.
    La substitution n'est fidele que si chaque $n apparait une seule fois et
    dans l'ordre : c'est le cas dans la CTE v, et on le verifie plutot que de
    le supposer.
    """
    nums = [int(m.group(1)) for m in re.finditer(r"\$(\d+)", query)]
    assert nums == list(range(1, len(FIELDS) + 1)), (
        "les parametres $n doivent apparaitre une fois chacun et dans l'ordre, "
        "trouve : %s" % nums
    )
    return re.sub(r"\$\d+", "%s", query)


def ligne(**kw):
    """Construit la liste des 15 parametres, tous passes en texte comme le fait n8n."""
    base = dict.fromkeys(FIELDS, "")
    base.update({
        "id_declaration": 1, "ligne_num": 1, "id_customer": 100,
        "declaration_reference": "DJI-0001", "nom_du_client": "Revendeur SARL - Jean",
        "type_de_promo": "Promo", "ref_article": "CP.MA.001", "designation": "Mini 4",
        "date_debut": "2026-06-01", "date_fin": "2026-06-30", "quantite": 5,
        "compensation_ht": "20.00", "total": "100.00", "ref_avoir": "",
        "date_declaration": "2026-07-02 10:00:00",
    })
    base.update(kw)
    return [None if v is None else str(v) for v in (base[f] for f in FIELDS)]


TESTS = []


def test(name):
    def deco(fn):
        TESTS.append((name, fn))
        return fn
    return deco


@test("1re declaration d'une promo : aucune anomalie")
def t1(run):
    r = run(ligne())
    assert r["is_doublon"] is False, r
    assert r["is_retard"] is False, r


@test("2e declaration, meme client + ref + date_fin : DOUBLON trace")
def t2(run):
    run(ligne(id_declaration=1, declaration_reference="DJI-0001",
              date_declaration="2026-07-02 10:00:00"))
    r = run(ligne(id_declaration=2, declaration_reference="DJI-0002",
                  date_declaration="2026-07-05 09:00:00"))
    assert r["is_doublon"] is True, r
    assert r["doublon_de_reference"] == "DJI-0001", r


@test("meme ref mais autre periode de promo : pas un doublon")
def t3(run):
    run(ligne(id_declaration=1, date_fin="2026-06-30"))
    r = run(ligne(id_declaration=2, date_fin="2026-09-30"))
    assert r["is_doublon"] is False, r


@test("meme promo mais autre client : pas un doublon")
def t4(run):
    run(ligne(id_declaration=1, id_customer=100))
    r = run(ligne(id_declaration=2, id_customer=200))
    assert r["is_doublon"] is False, r


@test("declaration a J+8 apres la fin de promo : RETARD")
def t5(run):
    r = run(ligne(date_fin="2026-06-30", date_declaration="2026-07-08 10:00:00"))
    assert r["is_retard"] is True, r


@test("declaration a J+7 pile : pas de retard (borne incluse)")
def t6(run):
    r = run(ligne(date_fin="2026-06-30", date_declaration="2026-07-07 23:00:00"))
    assert r["is_retard"] is False, r


@test("2 lignes de la MEME declaration sur la meme promo : pas un doublon")
def t7(run):
    run(ligne(id_declaration=1, ligne_num=1))
    r = run(ligne(id_declaration=1, ligne_num=2))
    assert r["is_doublon"] is False, r


@test("rejouer la meme ligne n'insere rien (idempotence)")
def t8(run):
    assert run(ligne(id_declaration=1, ligne_num=1)) is not None
    assert run(ligne(id_declaration=1, ligne_num=1)) is None


@test("promo sans date de fin : doublon detecte quand meme")
def t9(run):
    run(ligne(id_declaration=1, date_fin="", declaration_reference="DJI-0001"))
    r = run(ligne(id_declaration=2, date_fin=""))
    assert r["is_doublon"] is True, r
    assert r["doublon_de_reference"] == "DJI-0001", r


@test("cumul des deux anomalies sur une meme ligne")
def t10(run):
    run(ligne(id_declaration=1, date_fin="2026-06-30"))
    r = run(ligne(id_declaration=2, date_fin="2026-06-30",
                  date_declaration="2026-07-20 10:00:00"))
    assert r["is_doublon"] is True and r["is_retard"] is True, r


def main():
    query = load_query()
    ddl = io.open(DDL, encoding="utf-8").read()

    try:
        conn = psycopg.connect(DSN, autocommit=True)
    except Exception as e:
        print("Impossible de joindre Postgres (%s)\n  %s" % (DSN, e))
        return 2

    failures = []
    with conn:
        for name, fn in TESTS:
            with conn.cursor(row_factory=psycopg.rows.dict_row) as cur:
                cur.execute("DROP TABLE IF EXISTS public.declarations_export CASCADE")
                cur.execute(ddl)

                def run(params, _cur=cur):
                    _cur.execute(query, params)
                    rows = _cur.fetchall()
                    return rows[0] if rows else None

                try:
                    fn(run)
                    print("  OK   %s" % name)
                except AssertionError as e:
                    failures.append((name, e))
                    print("  FAIL %s\n       %s" % (name, e))

    print("\n%d/%d tests passes" % (len(TESTS) - len(failures), len(TESTS)))
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
