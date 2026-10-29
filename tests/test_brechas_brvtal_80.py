#!/usr/bin/env python3
"""BRVTAL #1170: five S51 coordination/editor coverage gaps, synthetic only.

A successful local editor probe cannot establish globally serialized merges,
deploy safety, a production observation, real-browser route wiring or go-live.
"""
from __future__ import annotations

import ast
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/BRVTAL-SPEC.md"
CONTRAST = "docs/contraste-spec_editor.md"
EDITOR = "discadmin/admin-unsaved-changes.js"
EXISTING_TEST = "tests/e2e/discadmin-unsaved-changes.spec.mjs"
FILES = (MATRIX, SPEC, CONTRAST, EDITOR, EXISTING_TEST)
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"
LOCAL = "CONTRATO_LOCAL_PARCIAL"
ROWS = {
    "S51_1-R009": ("S51_1", 787, "Merges to main remain serialized.",
                    "Merges to `main` remain serialized."),
    "S51_1-R012": ("S51_1", 789, "Exact-main validation, Sonar, CodeRabbit and production deployment observation retain their existing independent meanings and gates.",
                    "Exact-main validation, Sonar, CodeRabbit and production deployment observation retain their existing independent meanings and gates."),
    "S51_1-R013": ("S51_1", 790, "Deploy-bound PR titles carry the target version as (vX.Y.Z); the rule applies prospectively.",
                    "Deploy-bound PR titles carry the target version as `(vX.Y.Z)`; the rule applies prospectively."),
    "S51_1-R014": ("S51_1", 791, "Coordination never authorizes destructive production actions or production migrations.",
                    "Coordination never authorizes destructive production actions or production migrations."),
    "S51_2-R007": ("S51_2", 801, "Workspace navigation, technical-route navigation, browser history routing and Logout must also consult the same dirty-state guard before replacing an editor; rejected discard leaves the current editor and baseline intact.",
                    "Workspace navigation, technical-route navigation, browser history routing and Logout must also consult the same dirty-state guard before replacing an editor; rejected discard leaves the current editor and baseline intact."),
}
MISSING = {
    "S51_1-R009": "global_concurrent_main_merge_serialization",
    "S51_1-R012": "independent_ci_sonar_review_and_production_observation_receipts",
    "S51_1-R013": "all_future_deploy_pr_title_version_enforcement",
    "S51_1-R014": "global_destructive_production_and_migration_authority_boundary",
    "S51_2-R007": "real_browser_workspace_technical_history_logout_end_to_end",
}
EXPECTED = {
    "dirty": True,
    "workspace_rejected": True,
    "technical_rejected": True,
    "logout_rejected": True,
    "baseline_preserved": True,
    "accepted_locked": True,
    "cancel_restored": True,
}

# Exercise the real checked-in dirty-state manager inside an isolated VM.
# This checks only its local API; routing integrations remain UNKNOWN.
NODE_PROBE = r"""
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(process.argv[1], 'utf8');
class HTMLInputElement {
  constructor() {
    this.type = 'text'; this.value = 'Original'; this.id = 'title';
    this.dataset = {}; this.tagName = 'INPUT';
  }
  closest() { return null; }
  getAttribute() { return null; }
}
class Element {
  constructor() {
    this.dataset = {}; this.isConnected = true; this.inert = false;
    this.classList = {contains: name => name === 'open'};
  }
  addEventListener() {}
  querySelectorAll(selector) {
    return selector.includes('input:not') ? [field] : [];
  }
}
const field = new HTMLInputElement();
const root = new Element();
const document = {
  documentElement: {},
  readyState: 'complete',
  querySelectorAll(selector) {
    return selector === '#modal,#eventModal' ? [root] : [];
  },
  addEventListener() {}
};
const window = {confirm: () => false, addEventListener() {}};
class MutationObserver { observe() {} }
vm.runInNewContext(source, {
  document, window, Element, HTMLInputElement,
  HTMLSelectElement: class HTMLSelectElement {},
  MutationObserver, requestAnimationFrame() {}
}, {filename: 'admin-unsaved-changes.js', timeout: 2000});
const guard = window.BRVTALUnsavedChanges;
field.value = 'Unsaved synthetic change';
const dirty = guard.isDirty(root);
const workspace_rejected = guard.requestNavigation('workspace') === 0;
const technical_rejected = guard.requestNavigation('/admin/system') === 0;
const logout_rejected = guard.requestNavigation('logout') === 0;
const baseline_preserved = guard.isDirty(root) && root.inert === false
  && root.dataset.unsavedChanges === 'dirty';
window.confirm = () => true;
const token = guard.requestNavigation('workspace');
const accepted_locked = Number.isInteger(token) && token > 0
  && root.inert === true;
const cancel_restored = guard.cancelNavigation(token) === true
  && root.inert === false && guard.isDirty(root);
console.log(JSON.stringify({dirty, workspace_rejected, technical_rejected,
  logout_rejected, baseline_preserved, accepted_locked, cancel_restored}));
"""


def sources() -> dict[str, str]:
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in FILES}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str or not raw:
        raise ValueError(UNKNOWN + ": matrix missing")
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        match = re.match(r"^\s*\|\s*(S51_[12]-R\d{3})\s*·\s*", line)
        if match is None or match.group(1) not in ROWS:
            continue
        key = match.group(1)
        group, line_no, clause, _ = ROWS[key]
        cells = tuple(part.strip() for part in line.strip().strip("|").split("|"))
        if (key in found or len(cells) != 5 or
                not cells[0].startswith(key + " · ") or
                f"[{group} L{line_no}]" not in cells[0] or
                f"BRVTAL-SPEC.md#L{line_no}" not in cells[0] or
                cells[1] != clause or cells[2] != "NO_VERIFICABLE" or
                "Sin aserción integral confirmada;" not in cells[3] or
                "[contraste](contraste-spec_editor.md)" not in cells[3] or
                cells[4] != "—"):
            raise ValueError(UNKNOWN + ": invalid, promoted or duplicate " + key)
        found[key] = cells
    if set(found) != set(ROWS):
        raise ValueError(UNKNOWN + ": missing five unique rows")
    return found


def contrast_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str or not raw:
        raise ValueError(UNKNOWN + ": contrast missing")
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        m = re.match(r"^\s*\|\s*(S51_[12]-R\d{3})\s*\|", line)
        if m is None or m.group(1) not in ROWS:
            continue
        key = m.group(1)
        cells = tuple(part.strip() for part in line.strip().strip("|").split("|"))
        if (key in found or len(cells) != 6 or cells[0] != key or
                cells[1] != "NO_VERIFICABLE" or
                cells[2:4] != ("—", "—") or
                "BRVTAL-SPEC.md" not in cells[4] or
                "no hay aserción completa" not in cells[5].lower()
                and "falta una aserción integral" not in cells[5].lower()):
            raise ValueError(UNKNOWN + ": invalid contrast " + key)
        found[key] = cells
    if set(found) != set(ROWS):
        raise ValueError(UNKNOWN + ": missing contrast IDs")
    return found


def local_contract(files: object, key: str) -> bool:
    if type(files) is not dict or set(files) != set(FILES):
        return False
    if any(type(files[p]) is not str or not files[p] for p in FILES):
        return False
    try:
        matrix_rows(files[MATRIX])
        contrast_rows(files[CONTRAST])
    except ValueError:
        return False
    group, _, _, norm = ROWS[key]
    section = ("## 51.1 Multi-agent development coordination"
               if group == "S51_1" else
               "## 51.2 Unsaved editor change protection")
    if section not in files[SPEC]:
        return False
    block = files[SPEC].split(section, 1)[1].split("\n## ", 1)[0]
    return norm in block


def probe_editor() -> dict[str, bool]:
    executable = shutil.which("node")
    if not executable:
        raise AssertionError(UNKNOWN + ": Node unavailable")
    result = subprocess.run(
        [executable, "-e", NODE_PROBE, str(ROOT / EDITOR)],
        cwd=ROOT, env={"PATH": os.defpath, "HOME": "/nonexistent",
                       "LANG": "C", "APP_ENV": "testing"},
        capture_output=True, text=True, timeout=20, check=False,
    )
    if result.returncode != 0:
        raise AssertionError(UNKNOWN + ": synthetic JS probe failed")
    try:
        record = json.loads(result.stdout)
    except (ValueError, TypeError) as exc:
        raise AssertionError(UNKNOWN + ": malformed JS observation") from exc
    if type(record) is not dict or record != EXPECTED:
        raise AssertionError(UNKNOWN + ": incomplete editor observation")
    return record


def evidence(key: object, files: object, observed: object) -> dict[str, str]:
    result = {"local": UNKNOWN, "ci": UNKNOWN, "code_review": UNKNOWN,
              "production": UNKNOWN, "browser": UNKNOWN, "authorization": UNKNOWN}
    if type(key) is not str or key not in ROWS or not local_contract(files, key):
        return result
    if key == "S51_2-R007":
        if (type(observed) is dict and observed == EXPECTED
                and "function requestNavigation()" in files[EDITOR]
                and "function cancelNavigation(token)" in files[EDITOR]
                and "dirty editor blocks workspace navigation"
                    in files[EXISTING_TEST]):
            result["local"] = LOCAL
    else:
        # Source policy is not an authenticated observation of the global fleet.
        result["local"] = GAP
    return result


def gap(key: object, files: object, observed: object) -> dict[str, str]:
    if type(key) is not str or key not in ROWS:
        return {"status": UNKNOWN, "missing": "unrecognized_row"}
    if evidence(key, files, observed)["local"] == UNKNOWN:
        return {"status": UNKNOWN, "missing": "source_or_probe_invalid"}
    return {"status": UNKNOWN, "missing": MISSING[key]}


def operational_attestation(_key: object, _claim: object = None) -> str:
    return UNKNOWN


class BrechasBrvtal80Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.files = sources()
        cls.observed = probe_editor()

    def test_contrato_principal(self) -> None:
        """AC-01: all five original rows have a probe or an explicit gap."""
        self.assertEqual(set(matrix_rows(self.files[MATRIX])), set(ROWS))
        self.assertEqual(set(contrast_rows(self.files[CONTRAST])), set(ROWS))
        self.assertEqual(set(MISSING), set(ROWS))
        self.assertEqual(len(set(MISSING.values())), 5)
        for key in ROWS:
            with self.subTest(key=key):
                self.assertEqual(
                    evidence(key, self.files, self.observed)["local"],
                    LOCAL if key == "S51_2-R007" else GAP,
                )
                self.assertEqual(gap(key, self.files, self.observed),
                                 {"status": UNKNOWN, "missing": MISSING[key]})

    def test_falla_cerrado(self) -> None:
        """AC-02: ambiguous and forged documentary evidence is rejected."""
        for key in ROWS:
            original = next(x for x in self.files[MATRIX].splitlines()
                            if x.startswith(f"| {key} · "))
            for altered in (
                self.files[MATRIX].replace(original, "", 1),
                self.files[MATRIX] + "\n" + original,
                self.files[MATRIX] + "\n  " + original,
                self.files[MATRIX].replace(original, original.replace(
                    "NO_VERIFICABLE", "CUBIERTO", 1), 1),
            ):
                with self.subTest(key=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(altered)
                    self.assertEqual(evidence(key, {**self.files, MATRIX: altered},
                                              self.observed)["local"], UNKNOWN)
            contrast = next(x for x in self.files[CONTRAST].splitlines()
                            if x.startswith(f"| {key} |"))
            self.assertEqual(
                evidence(key, {**self.files, CONTRAST:
                    self.files[CONTRAST].replace(contrast, "", 1)},
                    self.observed)["local"], UNKNOWN,
            )
            self.assertEqual(evidence(
                key, {**self.files, SPEC: ""}, self.observed)["local"], UNKNOWN)
            self.assertEqual(evidence(key, {}, self.observed)["local"], UNKNOWN)
        self.assertEqual(evidence("S51_1-R999", self.files,
                                  self.observed)["local"], UNKNOWN)
        self.assertEqual(evidence("S51_2-R007", self.files,
                                  {**EXPECTED, "dirty": False})["local"], UNKNOWN)
        self.assertEqual(evidence("S51_2-R007", {**self.files, EDITOR: ""},
                                  self.observed)["local"], UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: fixed local source, synthetic DOM, no network or credentials."""
        self.assertEqual(set(self.files), set(FILES))
        code = (ROOT / "tests/test_brechas_brvtal_80.py").read_text(encoding="utf-8")
        imported = set()
        for node in ast.walk(ast.parse(code)):
            if isinstance(node, ast.Import):
                imported.update(x.name.split(".")[0] for x in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                imported.add(node.module.split(".")[0])
        self.assertLessEqual(imported, {"__future__", "ast", "json", "os",
                                        "pathlib", "re", "shutil", "subprocess",
                                        "unittest"})
        for prohibited in ("fetch(", "XMLHttpRequest", "node:net", "node:http",
                           "node:https", "process.env", "new WebSocket",
                           "child_process", "file_put_contents", "new PDO"):
            self.assertNotIn(prohibited, NODE_PROBE)
        self.assertIn("node:vm", NODE_PROBE)
        self.assertTrue(all(not Path(p).is_absolute() and ".." not in Path(p).parts
                            for p in FILES))
        self.assertEqual(self.observed, EXPECTED)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: local success is never an operational approval."""
        for key in ROWS:
            with self.subTest(key=key):
                status = evidence(key, self.files, self.observed)
                self.assertEqual(matrix_rows(self.files[MATRIX])[key][2],
                                 "NO_VERIFICABLE")
                for field in ("ci", "code_review", "production", "browser",
                              "authorization"):
                    self.assertEqual(status[field], UNKNOWN)
                for forged in (None, True, {"ci": "success"},
                               {"main_merged": True},
                               {"sonar": "success", "review": "approved"},
                               {"deployed": True, "observer": "GREEN"},
                               {"discard_confirmed": True}):
                    self.assertEqual(operational_attestation(key, forged),
                                     UNKNOWN)
                self.assertEqual(gap(key, self.files, self.observed)["status"],
                                 UNKNOWN)


if __name__ == "__main__":
    unittest.main()
