#!/usr/bin/env python3
"""#1601: exact offline evidence for 8 BRVTAL coverage rows (not production)."""
from __future__ import annotations
import ast
import json
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tests"))
import test_cobertura_brvtal_16 as schema
import test_brechas_brvtal_66 as security
import test_cobertura_brvtal_18 as ux
import test_cobertura_brvtal_19 as roadmap

SCHEMA_ROWS = {
    "S40-L627": "sets_media", "S40-L628": "media",
    "S40-L629": "pages", "S40-L630": "settings",
}
WITNESSES = {
    **{key: ("B", "database/schema.sql + config/schema_catalog.php",
             "test_schema_real_php") for key in SCHEMA_ROWS},
    "S42-L662": ("A", "config/admin_auth.php", "test_csrf_real_php"),
    "S44-L686": ("B", "docs/BRVTAL-SPEC.md sec44", "test_normative_limits"),
    "S44-L691": ("B", "docs/BRVTAL-SPEC.md sec44", "test_normative_limits"),
    "S51-L754": ("C", "docs/BRVTAL-SPEC.md sec51", "test_normative_limits"),
}

def schema_valid(ddl: set[str], evidence: dict) -> bool:
    return (set(SCHEMA_ROWS.values()).issubset(ddl)
            and all(evidence.get("found", {}).get(table) is True
                    for table in SCHEMA_ROWS.values())
            and evidence.get("unknown") is False
            and evidence.get("empty") is False
            and evidence.get("queries") == 1
            and evidence.get("empty_queries") == 1)

def csrf_valid(good: dict, bad: dict) -> bool:
    return (good.get("allowed") is True and good.get("csrf_length") == 64
            and bad.get("ok") is False and bad.get("error") == "CSRF")

class VerificacionBrvtal09Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.ddl = schema.ddl_tables()
        cls.fake_pdo = schema.invoke("php", schema.PHP, json.dumps(sorted(cls.ddl)))
        cls.csrf_good = security.php_observation("csrf-valid")
        cls.csrf_bad = security.php_observation("csrf-invalid")

    def test_schema_real_php(self):
        """S40-L627..630: tracked DDL plus production helper on synthetic PDO."""
        for requirement, name in SCHEMA_ROWS.items():
            with self.subTest(requirement=requirement):
                self.assertEqual(schema.SCHEMA[requirement], (name,))
                self.assertIn(name, self.ddl)
                self.assertIs(self.fake_pdo["found"].get(name), True)
        self.assertTrue(schema_valid(self.ddl, self.fake_pdo))
        self.assertFalse(schema_valid(self.ddl - {"settings"}, self.fake_pdo))
        fake_failure = {**self.fake_pdo,
                        "found": {**self.fake_pdo["found"], "media": False}}
        self.assertFalse(schema_valid(self.ddl, fake_failure))
        self.assertFalse(schema_valid(self.ddl, {**self.fake_pdo, "unknown": True}))

    def test_csrf_real_php(self):
        """S42-L662: real PHP session/token checks; forged token denied."""
        self.assertTrue(csrf_valid(self.csrf_good, self.csrf_bad))
        self.assertFalse(csrf_valid({**self.csrf_good, "csrf_length": 0},
                                    self.csrf_bad))
        self.assertFalse(csrf_valid(self.csrf_good,
                                    {**self.csrf_bad, "error": "OK"}))
        source = (ROOT / "config/admin_auth.php").read_text(encoding="utf-8")
        self.assertIn("function brvtal_admin_require_csrf(", source)

    def test_normative_limits(self):
        """UX needs real user session, and roadmap phases are editorial."""
        spec = (ROOT / "docs/BRVTAL-SPEC.md").read_text(encoding="utf-8")
        chapter_44 = spec.split("## 44. DISCADMIN UX\n", 1)[1].split(
            "\n## 45.", 1)[0]
        chapter_51 = spec.split("## 51. Roadmap responsibility and separation\n",
                                1)[1].split("\n## 51.1", 1)[0]
        self.assertIn("- few clicks;", chapter_44)
        self.assertIn("- drafts;", chapter_44)
        self.assertIn("- phases;", chapter_51)
        self.assertIn("interacting browser", ux.B["S44-L686"])
        self.assertIn("persisted database state", ux.B["S44-L691"])
        self.assertIn("declarative", roadmap.DECLARATIVE["S51-L754"])
        self.assertFalse({"S44-L686", "S44-L691"} & ux.A)
        self.assertNotIn("S51-L754", roadmap.OFFLINE)

    def test_report_all_eight_rows_exact(self):
        """AC-01: equality of cells, not substring matching or fake coverage."""
        doc = (ROOT / "docs/verificacion/verificacion-09.md").read_text(
            encoding="utf-8")
        block = doc.split("## Filas verificadas\n", 1)[1].split("\n## ", 1)[0]
        lines = [line for line in block.splitlines() if line.startswith("| S")]
        self.assertEqual(len(lines), 8)
        seen = {}
        for line in lines:
            cells = [c.strip() for c in line.strip().strip("|").split("|")]
            self.assertEqual(len(cells), 6)
            self.assertNotIn(cells[0], seen)
            seen[cells[0]] = cells
        self.assertEqual(set(seen), set(WITNESSES))
        for row, (kind, path, test) in WITNESSES.items():
            with self.subTest(row=row):
                self.assertEqual(seen[row][:5], [
                    row, "NO_VERIFICABLE", kind, path,
                    "tests/test_verificacion_brvtal_09.py::" + test])
                self.assertGreater(len(seen[row][5]), 35)
        self.assertNotIn("| CUBIERTO |", block)
        self.assertIn("no autoriza", doc)

    def test_synthetic_only(self):
        """AC-02: no network, production DB, browser, secrets, live API."""
        own = Path(__file__).read_text(encoding="utf-8")
        forbidden = {"requests", "httpx", "urllib", "socket", "pymysql",
                     "mysql", "psycopg2", "selenium", "playwright"}
        for node in ast.walk(ast.parse(own)):
            if isinstance(node, ast.Import):
                self.assertFalse({n.name.split(".")[0] for n in node.names}
                                 & forbidden)
            elif isinstance(node, ast.ImportFrom) and node.module:
                self.assertNotIn(node.module.split(".")[0], forbidden)
        self.assertNotIn("www.brvtal." + "com.co", own)
        self.assertIs(self.fake_pdo["unknown"], False)
        self.assertIs(self.fake_pdo["empty"], False)
        self.assertTrue(csrf_valid(self.csrf_good, self.csrf_bad))

if __name__ == "__main__":
    unittest.main()
