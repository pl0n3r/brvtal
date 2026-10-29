#!/usr/bin/env python3
"""#1284: S39/S39B real PHP and JS runtime contracts, isolated offline.

Never read docs/matriz-cobertura*.md: reconciliation owns that state.
Authentic external CI, authenticated UI and production remain separate gates.
"""
from __future__ import annotations
from datetime import date
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SHA_A = "abcdef01" + "2" * 32
SHA_B = "76543210" + "a" * 32
MODULES = ("events", "artists", "releases", "sets", "media", "pages", "blog")
PROVEN = {
    "S39-R003", "S39-R005", "S39-R006", "S39-R007",
    "S39-R008", "S39-R010", "S39B-R001", "S39B-R002",
    "S39B-R003", "S39B-R005", "S39B-R006", "S39B-R007",
}
NEEDS_REAL = {
    "S39-R002", "S39-R009", "S39B-R008", "S39B-R009", "S39B-R010",
}
DOCUMENTAL = {"S39-R001", "S39-R004", "S39B-R004"}


def helper(name):
    """Import pre-existing production-runtime probes, not their matrix readers."""
    if name not in {"test_brechas_brvtal_57", "test_brechas_brvtal_59"}:
        raise AssertionError("not an approved local executable witness")
    file = ROOT / "tests" / (name + ".py")
    spec = importlib.util.spec_from_file_location(name, file)
    if spec is None or spec.loader is None:
        raise AssertionError("missing executable local witness")
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


def invalid_grid_inputs():
    """Execute the real PHP normalization and permissions boundary offline."""
    probe = r"""
require $argv[1];
$errors = [];
foreach ([
  'admin'=>fn()=>brvtalAdminGridSettingKey(0,'events'),
  'module'=>fn()=>brvtalAdminGridSettingKey(7,'invalid'),
  'defaults'=>fn()=>brvtalAdminGridDefaultColumns('invalid'),
  'columns'=>fn()=>brvtalAdminGridNormalizePreferences('events',['columns'=>'bad'])
] as $name=>$call) {
  try {$call();$errors[$name]='ACCEPTED';}
  catch (InvalidArgumentException $e) {$errors[$name]=$e->getMessage();}
}
echo json_encode($errors,JSON_THROW_ON_ERROR);
"""
    php = shutil.which("php")
    if php is None:
        raise AssertionError("PHP interpreter required")
    with tempfile.TemporaryDirectory(prefix="brvtal-1284-") as tmp:
        result = subprocess.run(
            [php, "-d", "allow_url_fopen=0", "-r", probe,
             str(ROOT / "config/admin_grid.php")],
            cwd=tmp, env={"PATH": os.defpath, "HOME": tmp, "LANG": "C"},
            capture_output=True, text=True, timeout=20, check=False,
        )
    if result.returncode or result.stderr.strip():
        raise AssertionError("real PHP failed closed: " + result.stderr[-300:])
    return json.loads(result.stdout)


class CoberturaBrvtal15Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.release = helper("test_brechas_brvtal_57")
        cls.grid_runner = helper("test_brechas_brvtal_59")
        cls.version_file = ROOT / "config/version.php"
        cls.source = cls.version_file.read_text(encoding="utf-8")
        cls.exact = cls.release.run_php(cls.source, SHA_A)
        cls.other = cls.release.run_php(cls.source, SHA_B)
        cls.fallback = cls.release.run_php(cls.source)
        cls.invalid = cls.release.run_php(cls.source, "not-a-sha")
        cls.modified = cls.release.run_php(
            cls.release.modified_metadata(cls.source), SHA_A)
        # Executes canonical JS in Node VM + real admin_grid.php in PHP.
        cls.observed = cls.grid_runner.observe()
        cls.grid = cls.observed["grid"]
        cls.preferences = cls.observed["php"]

    def test_contrato_principal(self):
        """AC-01: twelve per-clause executable witnesses, not a matrix check."""
        self.assertEqual(len(PROVEN | NEEDS_REAL | DOCUMENTAL), 20)
        self.assertFalse(PROVEN & NEEDS_REAL)
        self.assertFalse(PROVEN & DOCUMENTAL)
        self.assertFalse(NEEDS_REAL & DOCUMENTAL)
        ci = (ROOT / ".github/workflows/update-release-metadata.yml").read_text(
            encoding="utf-8")
        js = (ROOT / "discadmin/admin-data-grid.js").read_text(encoding="utf-8")
        css = (ROOT / "discadmin/admin-data-grid.css").read_text(encoding="utf-8")
        shell = (ROOT / "discadmin/index.php").read_text(encoding="utf-8")
        with tempfile.TemporaryDirectory(prefix="brvtal-1284-version-") as tmp:
            before = self.version_file.read_bytes()
            checked = subprocess.run(
                [sys.executable, str(ROOT / "scripts/release-version.py"),
                 "--check-current"],
                cwd=ROOT, env={"PATH": os.defpath, "HOME": tmp, "LANG": "C"},
                capture_output=True, text=True, timeout=20, check=False,
            )
            unchanged = before == self.version_file.read_bytes()
        a, changed, fallback, other = (
            self.exact, self.modified, self.fallback, self.other)
        renders, states, prefs = (
            self.grid["rendered"], self.grid["states"], self.preferences)
        evidence = {
            "S39-R003": checked.returncode == 0 and unchanged
                and "Repository-only maintenance: product version may remain unchanged." in ci
                and "python scripts/release-version.py --check-current" in ci,
            "S39-R005": re.fullmatch(r"0\.\d+\.\d+", a["version"]) is not None
                and a["release"] == "v" + a["version"]
                and a["public"]["version"] == a["version"],
            "S39-R006": bool(a["build"]) and fallback["sha"] == a["build"].lower()
                and fallback["source"] == "release_fallback"
                and fallback["public"]["commit"] is None,
            "S39-R007": changed["environment"] == "SYNTHETIC"
                and changed["public"]["environment"] == "SYNTHETIC"
                and a["public"]["environment"] == a["environment"]
                and changed["source"] == "environment",
            "S39-R008": date.fromisoformat(a["release_date"]) is not None
                and changed["release_date"] == "2099-02-03"
                and changed["public"]["release_date"] == "2099-02-03"
                and changed["release"] == a["release"],
            "S39-R010": changed["version"] == other["version"] == a["version"]
                and changed["release"] == a["release"]
                and changed["public"]["release_identity"]
                    == a["public"]["release_identity"]
                and changed["sha"] == a["sha"] == SHA_A,
            "S39B-R001": self.grid["supported"] == [True] * 7
                and set(renders) == set(MODULES)
                and all(renders[m]["ok"] is True
                        and f'data-grid-module="{m}"' in renders[m]["html"]
                        for m in MODULES),
            "S39B-R002": "/discadmin/admin-data-grid.js" in shell
                and "/discadmin/admin-data-grid.css" in shell
                and ".admin-data-grid" in css
                and "window.BRVTALDataGrid = {render,clearSelection,supports,defaultColumns}"
                    in js and self.grid["invalid"] is True,
            "S39B-R003": all(
                f'data-grid-action="{action}"' in renders["events"]["html"]
                for action in ("lineup", "edit", "delete"))
                and 'data-grid-action="details"' in renders["media"]["html"]
                and 'data-grid-action="delete"' not in renders["media"]["html"]
                and "data-grid-membership-filter" in renders["artists"]["html"],
            "S39B-R005": len(states) == 4
                and [x["sort"][0] for x in states]
                    == ["none", "ascending", "descending", "none"]
                and [x["ordering"] for x in states] == ["1", "0", "0", "1"],
            "S39B-R006": [x["ids"] for x in states]
                    == [[3, 2, 1], [2, 1, 3], [1, 3, 2], [3, 2, 1]]
                and len(set(states[1]["ids"])) == 3,
            "S39B-R007": all(
                "data-grid-columns-toggle" in renders[m]["html"]
                and "data-grid-columns-reset" in renders[m]["html"]
                for m in MODULES)
                and prefs["restored"] == {"columns": prefs["defaults"]}
                and prefs["custom"] == {"columns": ["status", "primary"]}
                and prefs["bad"] is True,
        }
        self.assertEqual(set(evidence), PROVEN)
        for name, value in evidence.items():
            with self.subTest(requirement=name):
                self.assertIs(value, True, "real runtime behavior not exercised: " + name)

    def test_falla_cerrado(self):
        """AC-02: unknown authority, invalid SHA, module and columns rejected."""
        self.assertTrue(self.grid["invalid"])
        self.assertEqual(self.invalid["source"], "release_fallback")
        self.assertIsNone(self.invalid["public"]["commit"])
        self.assertIs(self.invalid["public"]["exact"], False)
        self.assertEqual(self.exact["source"], "environment")
        self.assertEqual(self.exact["sha"], SHA_A)
        self.assertEqual(invalid_grid_inputs(), {
            "admin": "INVALID_GRID_CONTEXT",
            "module": "INVALID_GRID_CONTEXT",
            "defaults": "INVALID_GRID_MODULE",
            "columns": "INVALID_GRID_COLUMNS",
        })
        self.assertTrue(self.preferences["bad"])
        self.assertEqual(self.grid["states"][0]["ids"], [3, 2, 1])
        self.assertNotEqual(self.grid["states"][1]["ids"],
                            self.grid["states"][0]["ids"])

    def test_sin_datos_reales(self):
        """AC-03: only synthetic Node VM, PHP subprocesses and local source."""
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own, r"(?m)^\s*(?:import|from)\s+(?:requests|socket|httpx|urllib)\b")
        for token in ("-----BEGIN " + "PRIVATE KEY-----",
                      "DATABASE_" + "URL", "GITHUB_" + "TOKEN"):
            self.assertNotIn(token, own)
        self.assertEqual(self.release.ROOT, ROOT)
        self.assertEqual(self.grid_runner.ROOT, ROOT)
        for path in ("config/version.php", "config/deployment.php",
                     "config/admin_grid.php", "discadmin/admin-data-grid.js"):
            self.assertTrue((ROOT / path).is_file())

    def test_ci_gate_contract(self):
        """AC-04: CI must pass on exact final SHA; only its wiring is local."""
        ci = (ROOT / ".github/workflows/update-release-metadata.yml").read_text(
            encoding="utf-8")
        self.assertIn("name: BRVTAL CI", ci)
        self.assertIn("  validate:\n    name: validate", ci)
        self.assertIn("needs: [preflight, coordination, coordination-pr,", ci)
        self.assertIn("run: npm run test:integration", ci)
        self.assertIn("Run PHP 8.5 compatibility and contract suite", ci)


if __name__ == "__main__":
    unittest.main()
