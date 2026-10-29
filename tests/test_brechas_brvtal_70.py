#!/usr/bin/env python3
"""BRVTAL #1160: five honest offline witnesses for DISCADMIN SPEC §44.

Only local static files and disposable synthetic DOM/JS are used. An isolated
witness is not evidence of real UI accessibility, user comprehension, a
production deployment, or successful CI. Those remain UNKNOWN.
"""
from __future__ import annotations

import ast
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
LOCAL = "LOCAL_PROBE"
CLAUSES = {
    "S44-R004": (689, "clear forms;"),
    "S44-R005": (690, "useful warnings;"),
    "S44-R006": (692, "consistent navigation;"),
    "S44-R007": (693, "recognizably BRVTAL visual language."),
    "S44-R008": (695, "Desktop is the primary admin experience, but tablet/mobile must not break."),
}
FILES = {
    "matrix": "docs/matriz-cobertura.md",
    "spec": "docs/BRVTAL-SPEC.md",
    "core": "discadmin/index-core.php",
    "css": "discadmin/admin-shell.css",
    "js": "discadmin/admin-shell.js",
    "e2e": "tests/e2e/discadmin-admin-shell.spec.mjs",
}
GAPS = {
    "S44-R005": "S44-R005: UNKNOWN — useful warning semantics are not exercised by this isolated test.",
    "S44-R007": "S44-R007: UNKNOWN — visual-brand recognition requires a validated design baseline and real rendering.",
}

# Execute the REAL shell JavaScript and REAL PHP-rendered field helpers, not an
# imitation of navigation logic. All DOM data, controls, events and text are
# disposable fixtures, and Node has no network module available in this VM.
NODE_PROBE = r"""
'use strict';
const vm = require('node:vm');
const fs = require('node:fs');
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
function list() {
  const state = new Set();
  return {
    contains(key) { return state.has(key); },
    toggle(key, enabled) { enabled ? state.add(key) : state.delete(key); },
  };
}
const button = {
  attr: {},
  focused: false,
  setAttribute(key, value) { this.attr[key] = value; },
  focus() { this.focused = true; },
};
const active = { focused: false, focus() { this.focused = true; } };
const container = {
  classList: list(),
  querySelector(selector) {
    if (selector === '.admin-menu-toggle') return button;
    if (selector === '.nav .active') return active;
    if (selector === '.nav button') return active;
    return null;
  },
};
const mq = {
  matches: true,
  handlers: [],
  addEventListener(event, fn) {
    if (event === 'change') this.handlers.push(fn);
  },
};
const listeners = {};
const document = {
  body: {classList: list()},
  querySelector(selector) { return selector === '#app .shell' ? container : null; },
  addEventListener(event, fn) { listeners[event] = fn; },
};
const window = {};
vm.runInNewContext(input.script, {window, document, matchMedia: () => mq});
window.toggleAdminNav();
const opened = container.classList.contains('admin-nav-open')
  && document.body.classList.contains('admin-nav-open')
  && button.attr['aria-expanded'] === 'true' && active.focused;
let prevented = false;
listeners.keydown({key: 'Escape', preventDefault() { prevented = true; }});
const escaped = prevented && !container.classList.contains('admin-nav-open')
  && button.focused && button.attr['aria-expanded'] === 'false';
window.toggleAdminNav();
listeners.click({target: {closest: (selector) =>
  selector.includes('#app .side .nav button') ? {} : null}});
const closedOnSelection = !container.classList.contains('admin-nav-open');
window.toggleAdminNav();
mq.matches = false;
for (const handler of mq.handlers) handler();
const desktop = !container.classList.contains('admin-nav-open')
  && button.attr['aria-expanded'] === 'false';
window.toggleAdminNav();
const noDuplicateDesktopNav = !container.classList.contains('admin-nav-open');
const esc = value => String(value ?? '').replace(/[&<>"']/g, ch =>
  ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[ch]));
const forms = vm.runInNewContext(input.helpers.join('\n') +
  "\n[field('title', 'Title *', '<script>')," +
  "area('description', 'Description', '<unsafe>')," +
  "select('status', 'Status', 'draft', [['draft', 'Draft'], ['published', 'Published']])]",
  {esc});
console.log(JSON.stringify({opened, escaped, closedOnSelection, desktop,
  noDuplicateDesktopNav, forms}));
"""


def sources() -> dict[str, str]:
    """Only repository-owned, fixed-path, text-only resources."""
    return {key: (ROOT / path).read_text(encoding="utf-8")
            if (ROOT / path).is_file() else ""
            for key, path in FILES.items()}


def rows(matrix: object) -> dict[str, tuple[str, ...]]:
    if type(matrix) is not str or not matrix:
        raise ValueError("UNKNOWN: missing coverage matrix")
    found: dict[str, tuple[str, ...]] = {}
    for line in matrix.splitlines():
        match = re.match(r"^\| (S44-R00[4-8]) · ", line)
        if not match:
            continue
        key = match.group(1)
        cells = tuple(part.strip() for part in line.strip("|").split("|"))
        anchor, phrase = CLAUSES[key]
        if (key in found or len(cells) != 5 or
                cells[1] != phrase or cells[2] != "NO_VERIFICABLE" or
                f"[S44 L{anchor}]" not in cells[0] or
                f"BRVTAL-SPEC.md#L{anchor})" not in cells[0] or
                "Sin aserción contrastada" not in cells[3]):
            raise ValueError("UNKNOWN: ambiguous or promoted " + key)
        found[key] = cells
    if set(found) != set(CLAUSES):
        raise ValueError("UNKNOWN: missing one or more S44 rows")
    return found


def source_contract(source: object, key: str) -> bool:
    if type(source) is not str or key not in CLAUSES:
        return False
    marker = "## 44. DISCADMIN UX\n"
    if source.count(marker) != 1:
        return False
    section = source.split(marker, 1)[1].split("\n## ", 1)[0]
    return section.count(CLAUSES[key][1]) == 1


def field_helpers(core: object) -> list[str]:
    """Extract exactly three existing one-line production helpers."""
    if type(core) is not str:
        return []
    helpers = []
    for name in ("field", "area", "select"):
        hits = re.findall(r"(?m)^function " + name + r"\(.*\}\s*$", core)
        if len(hits) != 1:
            return []
        helpers.append(hits[0])
    return helpers


def safe_env(home: str) -> dict[str, str]:
    return {"PATH": os.environ.get("PATH", "/usr/bin:/bin"),
            "HOME": home, "LANG": "C", "APP_ENV": "testing"}


def run_local(source: dict[str, str]) -> dict[str, object]:
    """Exercise real JS in a synthetic VM; missing tooling remains UNKNOWN."""
    if (type(source) is not dict or set(source) != set(FILES)
            or any(type(v) is not str or not v for v in source.values())
            or not shutil.which("node")):
        return {}
    helpers = field_helpers(source["core"])
    if len(helpers) != 3:
        return {}
    with tempfile.TemporaryDirectory(prefix="brvtal-1160-offline-") as temp:
        proc = subprocess.run(
            ["node", "-e", NODE_PROBE],
            input=json.dumps({"script": source["js"], "helpers": helpers}),
            cwd=temp, env=safe_env(temp), capture_output=True, text=True,
            timeout=20, check=False,
        )
    if proc.returncode:
        return {}
    try:
        result = json.loads(proc.stdout)
    except (json.JSONDecodeError, ValueError):
        return {}
    return result if type(result) is dict else {}


def evidence(key: str, source: object, probe: object) -> str:
    """Only explicit local witnesses pass; semantic gaps stay UNKNOWN."""
    if (key not in CLAUSES or type(source) is not dict or
            set(source) != set(FILES) or
            any(type(v) is not str or not v for v in source.values()) or
            not source_contract(source["spec"], key) or
            type(probe) is not dict):
        return UNKNOWN
    try:
        rows(source["matrix"])
    except ValueError:
        return UNKNOWN
    if key in GAPS:
        return UNKNOWN
    css = source["css"]
    e2e = source["e2e"]
    forms = probe.get("forms")
    if key == "S44-R004":
        good = (type(forms) is list and len(forms) == 3 and
                'for="f_title"' in forms[0] and 'id="f_title"' in forms[0] and
                "&lt;script&gt;" in forms[0] and
                'for="f_description"' in forms[1] and
                '<textarea id="f_description">' in forms[1] and
                "&lt;unsafe&gt;" in forms[1] and
                'for="f_status"' in forms[2] and
                '<select id="f_status">' in forms[2] and
                ".search,.field input,.field textarea,.field select{min-height:44px" in css
                and "input:focus-visible,textarea:focus-visible,select:focus-visible" in css)
    elif key == "S44-R006":
        good = (probe.get("opened") is True and
                probe.get("escaped") is True and
                probe.get("closedOnSelection") is True and
                probe.get("noDuplicateDesktopNav") is True and
                "mobile navigation uses the existing sidebar" in e2e and
                "Escape closes the mobile menu and returns focus" in e2e)
    else:
        good = (key == "S44-R008" and
                probe.get("opened") is True and probe.get("desktop") is True and
                probe.get("escaped") is True and
                "@media(max-width:850px)" in css and
                "@media(max-width:480px)" in css and
                "width:min(320px,calc(100vw - 48px))" in css and
                ".toolbar{flex-wrap:wrap}" in css and
                "await page.setViewportSize({ width: 390, height: 844 })" in e2e and
                "await page.setViewportSize({ width: 1280, height: 800 })" in e2e)
    return LOCAL if good else UNKNOWN


def operational(_key: object, _ci: object = None,
                _ui: object = None, _live: object = None) -> str:
    """Synthetic source inspection cannot certify UX, CI, tablet, or live."""
    return UNKNOWN


class BrechasBrvtal70Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.source = sources()
        cls.probe = run_local(cls.source)

    def test_contrato_principal(self) -> None:
        """AC-01: all five named requirements have a witness or explicit gap."""
        self.assertEqual(set(rows(self.source["matrix"])), set(CLAUSES))
        self.assertEqual(len(CLAUSES), 5)
        if not shutil.which("node"):
            self.skipTest("Node unavailable: local probes UNKNOWN")
        for key in CLAUSES:
            with self.subTest(id=key):
                self.assertTrue(source_contract(self.source["spec"], key))
                outcome = evidence(key, self.source, self.probe)
                if key in GAPS:
                    self.assertEqual(outcome, UNKNOWN)
                    self.assertIn(key + ": UNKNOWN", GAPS[key])
                else:
                    self.assertEqual(outcome, LOCAL, key)
                self.assertEqual(operational(key, outcome), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: every missing, promoted, duplicated, forged row is rejected."""
        matrix = self.source["matrix"]
        for key in CLAUSES:
            line = next(line for line in matrix.splitlines()
                        if line.startswith("| " + key + " · "))
            for corrupt in (
                matrix.replace(line, "", 1), matrix + "\n" + line,
                matrix.replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
                matrix.replace(line, "| " + key + " · corrupt |", 1),
            ):
                with self.subTest(key=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        rows(corrupt)
                    self.assertEqual(evidence(key, {**self.source, "matrix": corrupt},
                                              self.probe), UNKNOWN)
            for file in FILES:
                damaged = {**self.source, file: ""}
                self.assertEqual(evidence(key, damaged, self.probe), UNKNOWN)
            self.assertEqual(evidence(key, self.source, {}), UNKNOWN)
        self.assertEqual(evidence("S44-R999", self.source, self.probe), UNKNOWN)
        self.assertFalse(source_contract("## 44. DISCADMIN UX\n" * 2, "S44-R004"))

    def test_sin_datos_reales(self) -> None:
        """AC-03: no network calls, inherited secrets, live DB, or real PII."""
        self.assertEqual(set(FILES), {"matrix", "spec", "core", "css", "js", "e2e"})
        with tempfile.TemporaryDirectory(prefix="brvtal-1160-test-") as temp:
            env = safe_env(temp)
            self.assertEqual(env["APP_ENV"], "testing")
            for name in ("DB_PASSWORD", "DB_HOST", "DATABASE_URL", "APP_KEY",
                         "GITHUB_TOKEN", "GH_TOKEN", "HTTP_PROXY", "HTTPS_PROXY",
                         "MYSQL_PWD", "COOKIE", "AUTHORIZATION"):
                self.assertNotIn(name, env)
        script = Path(__file__).read_text(encoding="utf-8")
        tree = ast.parse(script)
        unsafe = {"requests", "httpx", "socket", "urllib", "playwright", "selenium"}
        imported = {node.module.split(".")[0] for node in ast.walk(tree)
                    if isinstance(node, ast.ImportFrom) and node.module}
        imported.update(alias.name.split(".")[0] for node in ast.walk(tree)
                        if isinstance(node, ast.Import) for alias in node.names)
        self.assertFalse(imported & unsafe)
        for disallowed in ("require('node:net')", "require('node:http')",
                           "require('node:https')", "require('node:child_process')"):
            self.assertNotIn(disallowed, NODE_PROBE)
        self.assertIn("synthetic", __doc__.lower())

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: green badges, fake claims and limited scope are not live proof."""
        self.assertEqual(set(rows(self.source["matrix"])), set(CLAUSES))
        for key in CLAUSES:
            with self.subTest(id=key):
                self.assertEqual(operational(key), UNKNOWN)
                for claim in (True, "success", {"ci": "success"},
                              {"tablet": "passed"}, {"deployed": True}):
                    self.assertEqual(operational(key, claim, claim, claim), UNKNOWN)
                self.assertEqual(rows(self.source["matrix"])[key][2],
                                 "NO_VERIFICABLE")
        self.assertEqual(evidence("S44-R005", self.source, self.probe), UNKNOWN)
        self.assertEqual(evidence("S44-R007", self.source, self.probe), UNKNOWN)
        self.assertIn("UNKNOWN", GAPS["S44-R005"])
        self.assertIn("UNKNOWN", GAPS["S44-R007"])


if __name__ == "__main__":
    unittest.main()
