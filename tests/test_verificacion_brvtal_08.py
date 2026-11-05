#!/usr/bin/env python3
"""#1600: fail-closed audit for exactly 50 BRVTAL coverage rows, offline only."""
from __future__ import annotations

import json
from pathlib import Path
import shutil
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
SPEC = ROOT / "docs/BRVTAL-SPEC.md"
REPORT = ROOT / "docs/verificacion/verificacion-08.md"
CASES = json.loads(r'''[{"id":"S39B-R013","requirement":"Artists, Sets, Releases and Blog keep visual ordering; drag/drop ordering is disabled whenever an explicit grid sort or partial filtered view is active;","category":"A_LOCAL","source":"discadmin/admin-data-grid.js","probe":"tests/test_cobertura_brvtal_16.py","probe_present":true,"actual":"Artists, Sets, Releases and Blog keep visual ordering; drag/drop ordering is disabled whenever an explicit grid sort or partial filtered view is active;","followup":"1285"},{"id":"S39B-R014","requirement":"Releases Catalog and Blog render through the same canonical grid pattern as the core record lists;","category":"A_LOCAL","source":"discadmin/admin-data-grid.js","probe":"tests/test_cobertura_brvtal_16.py","probe_present":true,"actual":"Releases Catalog and Blog render through the same canonical grid pattern as the core record lists;","followup":"1285"},{"id":"S39B-R015","requirement":"Media keeps its uploader and inspector around the canonical record table rather than maintaining a separate record-card interaction model.","category":"A_LOCAL","source":"discadmin/admin-data-grid.js","probe":"tests/test_cobertura_brvtal_16.py","probe_present":true,"actual":"Media keeps its uploader and inspector around the canonical record table rather than maintaining a separate record-card interaction model.","followup":"1285"},{"id":"S39B-R016","requirement":"The shared grid must remain keyboard/focus accessible and horizontally usable on narrow/mobile viewports.","category":"A_LOCAL","source":"discadmin/admin-data-grid.js","probe":"tests/test_cobertura_brvtal_16.py","probe_present":true,"actual":"The shared grid must remain keyboard/focus accessible and horizontally usable on narrow/mobile viewports.","followup":"1285"},{"id":"H212-01","requirement":"Traducción ES/EN: arquitectura provider-neutral; DeepL REAL requiere sustituir decisión OWNER B y resolver privacidad/proveedor.","category":"SUPERSEDED","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_01.py","probe_present":true,"actual":"**Owner decision (2026-11-04, #1440): BRVTAL's public website is Spanish-only","followup":"212"},{"id":"S03-L41","requirement":"Lenis;","category":"A_LOCAL","source":"js/menu-scroll-lock.js","probe":"tests/test_cobertura_brvtal_01.py","probe_present":true,"actual":"Lenis;","followup":"1270"},{"id":"S07-L74","requirement":"Home;","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_02.py","probe_present":false,"actual":"Home;","followup":"1271"},{"id":"S07-L75","requirement":"About;","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_02.py","probe_present":false,"actual":"About;","followup":"1271"},{"id":"S07-L76","requirement":"Manifesto;","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_02.py","probe_present":false,"actual":"Manifesto;","followup":"1271"},{"id":"S07-L77","requirement":"Events;","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_02.py","probe_present":false,"actual":"Events;","followup":"1271"},{"id":"S07-L78","requirement":"Artists;","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_02.py","probe_present":false,"actual":"Artists;","followup":"1271"},{"id":"S07-L80","requirement":"Releases;","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_02.py","probe_present":false,"actual":"Releases;","followup":"1271"},{"id":"S07-L82","requirement":"Contact;","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_02.py","probe_present":false,"actual":"Contact;","followup":"1271"},{"id":"S07-L83","requirement":"Legal;","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_02.py","probe_present":false,"actual":"Legal;","followup":"1271"},{"id":"S07-L84","requirement":"Blog;","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_02.py","probe_present":false,"actual":"Blog;","followup":"1271"},{"id":"S09-L105","requirement":"drafts;","category":"A_LOCAL","source":"api/event-workflow-lib.php","probe":"tests/test_cobertura_brvtal_03.py","probe_present":true,"actual":"drafts;","followup":"1272"},{"id":"S09-L106","requirement":"publish;","category":"A_LOCAL","source":"api/event-workflow-lib.php","probe":"tests/test_cobertura_brvtal_03.py","probe_present":true,"actual":"publish;","followup":"1272"},{"id":"S09-L107","requirement":"archive;","category":"A_LOCAL","source":"api/event-workflow-lib.php","probe":"tests/test_cobertura_brvtal_03.py","probe_present":true,"actual":"archive;","followup":"1272"},{"id":"S09-L108","requirement":"preview;","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_03.py","probe_present":true,"actual":"preview;","followup":"1272"},{"id":"S09-L109","requirement":"media;","category":"A_LOCAL","source":"api/event-workflow-lib.php","probe":"tests/test_cobertura_brvtal_03.py","probe_present":true,"actual":"media;","followup":"1272"},{"id":"S09-L110","requirement":"SEO;","category":"A_LOCAL","source":"api/event-workflow-lib.php","probe":"tests/test_cobertura_brvtal_03.py","probe_present":true,"actual":"SEO;","followup":"1272"},{"id":"S09-L111","requirement":"statistics;","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_03.py","probe_present":true,"actual":"statistics;","followup":"1272"},{"id":"S09-L112","requirement":"tickets;","category":"A_LOCAL","source":"api/event-workflow-lib.php","probe":"tests/test_cobertura_brvtal_03.py","probe_present":true,"actual":"tickets;","followup":"1272"},{"id":"S09-L119","requirement":"draft;","category":"A_LOCAL","source":"api/event-workflow-lib.php","probe":"tests/test_cobertura_brvtal_03.py","probe_present":true,"actual":"draft;","followup":"1272"},{"id":"S09-L120","requirement":"published;","category":"A_LOCAL","source":"api/event-workflow-lib.php","probe":"tests/test_cobertura_brvtal_03.py","probe_present":true,"actual":"published;","followup":"1272"},{"id":"S09-L121","requirement":"archived;","category":"A_LOCAL","source":"api/event-workflow-lib.php","probe":"tests/test_cobertura_brvtal_03.py","probe_present":true,"actual":"archived;","followup":"1272"},{"id":"S09-L122","requirement":"upcoming;","category":"A_LOCAL","source":"api/event-workflow-lib.php","probe":"tests/test_cobertura_brvtal_04.py","probe_present":true,"actual":"upcoming;","followup":"1273"},{"id":"S09-L125","requirement":"sold_out;","category":"A_LOCAL","source":"api/event-workflow-lib.php","probe":"tests/test_cobertura_brvtal_04.py","probe_present":true,"actual":"sold_out;","followup":"1273"},{"id":"S09-L126","requirement":"cancelled;","category":"A_LOCAL","source":"api/event-workflow-lib.php","probe":"tests/test_cobertura_brvtal_04.py","probe_present":true,"actual":"cancelled;","followup":"1273"},{"id":"S09-L127","requirement":"finished.","category":"A_LOCAL","source":"api/event-workflow-lib.php","probe":"tests/test_cobertura_brvtal_04.py","probe_present":true,"actual":"finished.","followup":"1273"},{"id":"S13-L180","requirement":"Sets;","category":"C_DECLARATIVA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_04.py","probe_present":true,"actual":"Sets;","followup":"1273"},{"id":"S13-L181","requirement":"Releases.","category":"C_DECLARATIVA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_04.py","probe_present":true,"actual":"Releases.","followup":"1273"},{"id":"S24-L368","requirement":"one shell;","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_07.py","probe_present":true,"actual":"one shell;","followup":"1276"},{"id":"S24-L375","requirement":"Dashboard;","category":"SUPERSEDED","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_07.py","probe_present":true,"actual":"1. Dashboard","followup":"1276"},{"id":"S24-L376","requirement":"Events;","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_07.py","probe_present":true,"actual":"Events;","followup":"1276"},{"id":"S24-L377","requirement":"Artists;","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_07.py","probe_present":true,"actual":"Artists;","followup":"1276"},{"id":"S24-L378","requirement":"Releases;","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_07.py","probe_present":true,"actual":"Releases;","followup":"1276"},{"id":"S24-L379","requirement":"Sets;","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_07.py","probe_present":true,"actual":"Sets;","followup":"1276"},{"id":"S24-L380","requirement":"Media;","category":"SUPERSEDED","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_07.py","probe_present":true,"actual":"4. Media","followup":"1276"},{"id":"S24-L381","requirement":"Pages;","category":"SUPERSEDED","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_07.py","probe_present":true,"actual":"2. Pages","followup":"1276"},{"id":"S24-L382","requirement":"Blog;","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_07.py","probe_present":true,"actual":"Blog;","followup":"1276"},{"id":"S24-L385","requirement":"Settings;","category":"SUPERSEDED","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_08.py","probe_present":true,"actual":"11. Settings","followup":"1277"},{"id":"S26-L427","requirement":"CSRF;","category":"A_LOCAL","source":"config/admin_auth.php","probe":"tests/test_cobertura_brvtal_08.py","probe_present":true,"actual":"CSRF;","followup":"1277"},{"id":"S38-L592","requirement":"PHP syntax;","category":"A_LOCAL","source":"config/version.php","probe":"tests/test_cobertura_brvtal_14.py","probe_present":true,"actual":"PHP syntax;","followup":"1283"},{"id":"S38-L597","requirement":"config;","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_14.py","probe_present":true,"actual":"config;","followup":"1283"},{"id":"S38-L598","requirement":"secrets;","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_14.py","probe_present":true,"actual":"secrets;","followup":"1283"},{"id":"S38-L599","requirement":"integrity;","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_14.py","probe_present":true,"actual":"integrity;","followup":"1283"},{"id":"S40-L623","requirement":"`admins`;","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_16.py","probe_present":true,"actual":"`admins`;","followup":"1285"},{"id":"S40-L624","requirement":"`events`;","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_16.py","probe_present":true,"actual":"`events`;","followup":"1285"},{"id":"S40-L625","requirement":"`artists`;","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_16.py","probe_present":true,"actual":"`artists`;","followup":"1285"}]''')
CATEGORIES = {"A_LOCAL", "B_EXTERNA", "C_DECLARATIVA", "SUPERSEDED"}
EXPECT_IDS = {item["id"] for item in CASES}
REQUIRED_ALTERNATES = {
    "H212-01": "Spanish-only",
    "S24-L375": "1. Dashboard",
    "S24-L380": "4. Media",
    "S24-L381": "2. Pages",
    "S24-L385": "11. Settings",
}


def matrix_rows(source: str) -> dict[str, tuple[str, str]]:
    """Read actual canonical table; never count its summaries as evidence."""
    body = source.split("## Cobertura\n", 1)[1].split("\n## Resumen\n", 1)[0]
    found = {}
    for line in body.splitlines():
        if not line.startswith("| "):
            continue
        parts = [item.strip() for item in line.strip().strip("|").split("|")]
        if len(parts) != 5 or " · " not in parts[0]:
            continue
        ident = parts[0].split(" · ", 1)[0]
        if ident in found:
            raise ValueError("Duplicate coverage ID: " + ident)
        found[ident] = (parts[1], parts[2])
    return found


def report_rows(source: str) -> dict[str, list[str]]:
    found = {}
    for line in source.splitlines():
        if not line.startswith("| "):
            continue
        columns = [x.strip() for x in line.strip().strip("|").split("|")]
        if not columns or columns[0] not in EXPECT_IDS:
            continue
        if columns[0] in found or len(columns) != 8:
            raise ValueError("Duplicate or malformed ledger row: " + columns[0])
        found[columns[0]] = columns
    if set(found) != EXPECT_IDS or len(found) != 50:
        raise ValueError("Missing or fabricated rows in verification ledger")
    return found


def verify_contract(matrix: str, spec: str, report: str, sources=None):
    canonical = matrix_rows(matrix)
    ledger = report_rows(report)
    if len(CASES) != 50 or len(EXPECT_IDS) != 50:
        raise ValueError("Exactly fifty assigned rows are required")
    if not all(x in report for x in ("NO_VERIFICABLE", "UNKNOWN", "SUPERSEDED")):
        raise ValueError("Operational fail-closed disclaimers absent")
    if "validated_in_production" in report.lower():
        # The only allowed mention explicitly denies that claim.
        if "Resultado que no puede declarar" not in report:
            raise ValueError("False production accreditation")
    for item in CASES:
        ident = item["id"]
        if item["category"] not in CATEGORIES:
            raise ValueError("Unknown evidence class: " + ident)
        if canonical.get(ident) != (item["requirement"], "NO_VERIFICABLE"):
            raise ValueError("Matrix assertion/state drift: " + ident)
        actual = item["actual"]
        if actual not in spec:
            raise ValueError("Current source no longer establishes: " + ident)
        source_path = item["source"]
        if not source_path.startswith(("docs/", "discadmin/", "api/", "js/", "config/")):
            raise ValueError("Untrusted source path: " + ident)
        if not (ROOT / source_path).is_file():
            raise ValueError("Missing real implementation/reference: " + ident)
        if item["category"] == "A_LOCAL" and not item["probe_present"]:
            raise ValueError("Missing referenced executable test: " + ident)
        probe = item["probe"]
        if probe:
            if not probe.startswith("tests/") or not probe.endswith(".py"):
                raise ValueError("Untrusted test path: " + ident)
            exists = (ROOT / probe).is_file()
            if exists != item["probe_present"]:
                raise ValueError("Legacy test presence drift: " + ident)
        row = ledger[ident]
        expected = [
            ident, item["category"], item["requirement"],
            chr(96) + source_path + chr(96),
            chr(96) + actual.replace(chr(96), "'") + chr(96),
            chr(96) + (probe or "sin probe") + chr(96),
            "EXISTE" if item["probe_present"] else "AUSENTE/NO APLICABLE",
            "#" + item["followup"],
        ]
        if row != expected:
            raise ValueError("Ledger source/claim altered: " + ident)
    if "No public multilingual features" not in spec or (
        "Existing records in the previously implemented translation-cache table"
        not in spec
    ):
        raise ValueError("Spanish-only retention/privacy policy regressed")
    for ident, phrase in REQUIRED_ALTERNATES.items():
        if phrase not in spec or ledger[ident][1] != "SUPERSEDED":
            raise ValueError("Superseded editorial decision reactivated: " + ident)
    return len(ledger)


class VerificacionBrvtal08Tests(unittest.TestCase):
    def test_principal_cincuenta_filas_sin_acreditacion_falsa(self):
        self.assertEqual(50, verify_contract(
            MATRIX.read_text(encoding="utf-8"),
            SPEC.read_text(encoding="utf-8"),
            REPORT.read_text(encoding="utf-8"),
        ))

    def test_mutaciones_negativas(self):
        matrix = MATRIX.read_text(encoding="utf-8")
        spec = SPEC.read_text(encoding="utf-8")
        report = REPORT.read_text(encoding="utf-8")
        samples = [
            (matrix.replace(CASES[0]["requirement"] + " | NO_VERIFICABLE |",
                            CASES[0]["requirement"] + " | CUBIERTO |", 1),
             spec, report),
            (matrix, spec.replace("No public multilingual features",
                                  "Public multilingual features", 1), report),
            (matrix, spec, report.replace("| H212-01 |", "| H212-02 |", 1)),
            (matrix, spec, report.replace("| A_LOCAL |", "| CUBIERTO |", 1)),
            (matrix, spec, report.replace("AUSENTE/NO APLICABLE", "EXISTE", 1)),
            (matrix, spec, report.replace("1. Dashboard", "1. Other", 1)),
        ]
        for index, (altered_matrix, altered_spec, altered_report) in enumerate(samples):
            with self.subTest(mutation=index):
                with self.assertRaises(ValueError):
                    verify_contract(altered_matrix, altered_spec, altered_report)

    def test_contratos_reales_reutilizados_sin_operaciones_externas(self):
        """Exercise already merged real source fixtures for grid/workflow/auth."""
        for relative in (
            "tests/test_cobertura_brvtal_16.py",
            "tests/test_cobertura_brvtal_03.py",
            "tests/test_cobertura_brvtal_04.py",
        ):
            with self.subTest(module=relative):
                module = relative[6:-3].replace("/", ".")
                finished = subprocess.run(
                    [sys.executable, "-m", "unittest", "tests." + module],
                    cwd=ROOT, capture_output=True, text=True,
                    check=False, timeout=90,
                )
                self.assertEqual(0, finished.returncode,
                                 "Offline source contract failed: " + relative
                                 + "\n" + finished.stderr[-900:])


if __name__ == "__main__":
    unittest.main()
