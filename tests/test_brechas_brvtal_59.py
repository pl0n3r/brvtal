#!/usr/bin/env python3
"""BRVTAL #1149: five offline grid witnesses; production always UNKNOWN."""
from __future__ import annotations

import ast
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"
GAP = "EXPLICIT_UNKNOWN_GAP"
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/BRVTAL-SPEC.md"
GRID = "discadmin/admin-data-grid.js"
CONFIG = "config/admin_grid.php"
API = "api/admin-grid-preferences.php"
SETTINGS = "api/index.php"
E2E = "tests/e2e/discadmin-data-grid.spec.mjs"
WITNESS = "tests/test_brechas_brvtal_58.py"
FILES = (MATRIX, SPEC, GRID, CONFIG, API, SETTINGS, E2E, WITNESS)
IDS = tuple(f"S39B-R{i:03d}" for i in range(6, 11))
CLAUSES = {
    "S39B-R006": "explicit sorting is deterministic and uses record identity as a stable secondary order;",
    "S39B-R007": "column visibility is configurable through Columns / View and can be restored to module defaults;",
    "S39B-R008": "visible columns persist per authenticated administrator and per module through private admin.grid.. settings;",
    "S39B-R009": "those private preference records are not exposed through the generic Settings editor;",
    "S39B-R010": "every supported grid provides row selection, select-all for the current result set and clear selection;",
}
MODULES = ("events", "artists", "releases", "sets", "media", "pages", "blog")
PHP_PROBE = r"""
require $argv[1];
$modules = array_keys(brvtalAdminGridColumns());
$keys = [
 'a_events'=>brvtalAdminGridSettingKey(101,'events'),
 'b_events'=>brvtalAdminGridSettingKey(202,'events'),
 'a_blog'=>brvtalAdminGridSettingKey(101,'blog')
];
$defaults = brvtalAdminGridDefaultColumns('events');
$custom = brvtalAdminGridNormalizePreferences('events', [
 'columns'=>['status','primary','status','not-valid']
]);
$restored = brvtalAdminGridNormalizePreferences('events', []);
$bad = false;
try { brvtalAdminGridSettingKey(0,'events'); }
catch (InvalidArgumentException $e) { $bad = true; }
echo json_encode(compact('modules','keys','defaults','custom','restored','bad'),JSON_THROW_ON_ERROR);
"""

def sources():
    return {name: (ROOT / name).read_text(encoding="utf-8")
            if (ROOT / name).is_file() else "" for name in FILES}

def rows(raw):
    if type(raw) is not str or not raw:
        raise ValueError("UNKNOWN: matrix missing")
    found = {}
    for line in raw.splitlines():
        match = re.match(r"^\| (S39B-R00[6-9]|S39B-R010) · ", line)
        if not match:
            continue
        key = match.group(1)
        cells = tuple(value.strip() for value in line.strip("|").split("|"))
        number = 830 + int(key.split("R")[1]) - 6
        if (key in found or len(cells) != 5
                or not cells[0].startswith(f"{key} · [S39B L{number}]")
                or f"docs/BRVTAL-SPEC.md#L{number}" not in cells[0]
                or cells[1] != CLAUSES[key]
                or cells[2] != "NO_VERIFICABLE"
                or "Sin aserción contrastada" not in cells[3]
                or cells[4] != "—"):
            raise ValueError("UNKNOWN: malformed or duplicated " + key)
        found[key] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: missing requirements")
    return found

def observe():
    spec = importlib.util.spec_from_file_location("brvtal_grid58", ROOT / WITNESS)
    if spec is None or spec.loader is None:
        raise RuntimeError("UNKNOWN: no grid witness")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    grid = module.observe_grid()
    php = shutil.which("php")
    if php is None:
        raise RuntimeError("UNKNOWN: php absent")
    with tempfile.TemporaryDirectory(prefix="brvtal-1149-") as temp:
        env = {"PATH": os.defpath, "HOME": temp, "LANG": "C",
               "LC_ALL": "C", "APP_ENV": "testing"}
        completed = subprocess.run(
            [php, "-r", PHP_PROBE, str(ROOT / CONFIG)],
            cwd=temp, env=env, capture_output=True, text=True,
            timeout=20, check=False
        )
    if completed.returncode or completed.stderr.strip():
        raise RuntimeError("UNKNOWN: synthetic PHP failed " + completed.stderr[-200:])
    try:
        result = json.loads(completed.stdout)
    except ValueError as exc:
        raise RuntimeError("UNKNOWN: malformed PHP evidence") from exc
    return {"grid": grid, "php": result}

def evidence(key, src, proof):
    if (type(key) is not str or key not in IDS
            or type(src) is not dict or set(src) != set(FILES)
            or any(type(src[name]) is not str or not src[name] for name in FILES)
            or type(proof) is not dict or set(proof) != {"grid", "php"}):
        return UNKNOWN
    try:
        rows(src[MATRIX])
    except ValueError:
        return UNKNOWN
    spec = src[SPEC].split("## 39. Canonical DISCADMIN data grid", 1)
    phrase = ("visible columns persist per authenticated administrator"
              if key == "S39B-R008" else CLAUSES[key])
    if len(spec) != 2 or phrase not in spec[1].replace(chr(96), ""):
        return UNKNOWN
    grid, php = proof["grid"], proof["php"]
    if type(grid) is not dict or type(php) is not dict:
        return UNKNOWN
    rendered = grid.get("rendered")
    if (grid.get("supported") != [True] * 7 or grid.get("invalid") is not True
            or type(rendered) is not dict or set(rendered) != set(MODULES)
            or not all(type(rendered[m]) is dict and rendered[m].get("ok") is True
                       and type(rendered[m].get("html")) is str
                       for m in MODULES)):
        return UNKNOWN
    if key == "S39B-R006":
        states = grid.get("states")
        good = (type(states) is list and len(states) == 4
                and all(type(x) is dict for x in states)
                and [x.get("ids") for x in states] ==
                [[3,2,1],[2,1,3],[1,3,2],[3,2,1]])
    elif key == "S39B-R007":
        good = (all("data-grid-columns-toggle" in rendered[m]["html"]
                    and "data-grid-columns-reset" in rendered[m]["html"]
                    for m in MODULES)
                and "column chooser persists only the current module and restores defaults"
                in src[E2E]
                and "const saved = await savePreferences(state.module,defaults)"
                in src[GRID]
                and php.get("restored") == {"columns":php.get("defaults")})
    elif key == "S39B-R008":
        keys = php.get("keys")
        good = (php.get("modules") == list(MODULES) and type(keys) is dict
                and keys.get("a_events") == "admin.grid.101.events"
                and keys.get("b_events") == "admin.grid.202.events"
                and keys.get("a_blog") == "admin.grid.101.blog"
                and len(set(keys.values())) == 3
                and php.get("custom") == {"columns":["status","primary"]}
                and php.get("bad") is True
                and "brvtal_admin_require();" in src[API]
                and "brvtal_admin_require_csrf();" in src[API]
                and "brvtalAdminGridSettingKey($adminId, $module)" in src[API])
    elif key == "S39B-R009":
        good = ("setting_key NOT LIKE 'admin.grid.%'" in src[SETTINGS]
                and src[SETTINGS].count("str_starts_with($key, 'admin.grid.')") >= 2
                and "brvtal_admin_require();" in src[API])
        return GAP if good else UNKNOWN
    else:
        good = (all("data-grid-select-all" in rendered[m]["html"]
                    and "data-grid-clear" in rendered[m]["html"]
                    and "data-grid-select=" in rendered[m]["html"]
                    for m in MODULES)
                and "row selection supports select-all, clear and safe bulk-action handoff"
                in src[E2E]
                and "visibleIds.forEach(id => shouldSelect" in src[GRID]
                and "selected.clear();" in src[GRID])
    return LOCAL if good else UNKNOWN

def operational(_key, _attestation=None):
    return UNKNOWN

class BrechasBrvtal59Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.src = sources()
        cls.proof = observe()

    def test_contrato_principal(self):
        """AC-01: four offline witnesses plus private Settings exposure gap."""
        self.assertEqual(set(rows(self.src[MATRIX])), set(IDS))
        for key in IDS:
            with self.subTest(id=key):
                expected = GAP if key == "S39B-R009" else LOCAL
                self.assertEqual(evidence(key, self.src, self.proof), expected)
                self.assertEqual(operational(key, self.proof), UNKNOWN)

    def test_falla_cerrado(self):
        """AC-02: absent, duplicate, promoted and forged rows reject."""
        original = self.src[MATRIX]
        for key in IDS:
            line = next(x for x in original.splitlines()
                        if x.startswith("| " + key + " · "))
            variants = (original.replace(line, "", 1),
                        original + "\n" + line,
                        original.replace(line,
                                         line.replace("NO_VERIFICABLE", "CUBIERTO"),1))
            for bad in variants:
                with self.subTest(id=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        rows(bad)
                    self.assertEqual(
                        evidence(key, {**self.src, MATRIX: bad}, self.proof), UNKNOWN)
            for filename in FILES:
                self.assertEqual(evidence(
                    key, {**self.src, filename: ""}, self.proof), UNKNOWN)
            self.assertEqual(evidence(key, self.src, {}), UNKNOWN)
        altered = json.loads(json.dumps(self.proof))
        altered["grid"]["states"][1]["ids"] = [3,1,2]
        self.assertEqual(evidence("S39B-R006", self.src, altered), UNKNOWN)
        malformed_states = json.loads(json.dumps(self.proof))
        malformed_states["grid"]["states"][1] = None
        self.assertEqual(
            evidence("S39B-R006", self.src, malformed_states), UNKNOWN)
        for malformed_html in ("missing", None, [], 77):
            invalid = json.loads(json.dumps(self.proof))
            if malformed_html == "missing":
                del invalid["grid"]["rendered"]["events"]["html"]
            else:
                invalid["grid"]["rendered"]["events"]["html"] = malformed_html
            for key in ("S39B-R007", "S39B-R010"):
                with self.subTest(key=key, html=repr(malformed_html)):
                    self.assertEqual(
                        evidence(key, self.src, invalid), UNKNOWN)
        self.assertEqual(evidence("S39B-R999", self.src, self.proof), UNKNOWN)

    def test_sin_datos_reales(self):
        """AC-03: no provider credentials, DB, HTTP or real identities."""
        allowed = {
            "__future__", "ast", "importlib", "json", "os", "pathlib",
            "re", "shutil", "subprocess", "tempfile", "unittest"
        }
        # The imported neighbor is executable code too: audit its imports.
        for source_path in (Path(__file__), ROOT / WITNESS):
            imported = set()
            for item in ast.walk(ast.parse(
                    source_path.read_text(encoding="utf-8"))):
                if isinstance(item, ast.Import):
                    imported.update(x.name.split(".")[0] for x in item.names)
                elif isinstance(item, ast.ImportFrom) and item.module:
                    imported.add(item.module.split(".")[0])
            with self.subTest(source=source_path.name):
                self.assertLessEqual(imported, allowed)
        self.assertNotIn("new PDO(", PHP_PROBE)
        self.assertNotIn("curl_", PHP_PROBE)
        self.assertEqual(set(FILES), set(self.src))

    def test_estados_unknown_explicitos(self):
        """AC-04: synthetic green never certifies production or authentication."""
        for key in IDS:
            with self.subTest(id=key):
                self.assertEqual(rows(self.src[MATRIX])[key][2], "NO_VERIFICABLE")
                for value in (None, True, "success", {"ci":"success"},
                              {"live":True}, {"authenticated":True}):
                    self.assertEqual(operational(key, value), UNKNOWN)
        self.assertEqual(evidence("S39B-R009", self.src, self.proof), GAP)

if __name__ == "__main__":
    unittest.main()
