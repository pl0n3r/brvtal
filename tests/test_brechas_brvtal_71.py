#!/usr/bin/env python3
"""BRVTAL #1161: deterministic DISCADMIN sidebar/workspace coverage.

Browser behavior, visual quality and deployment remain UNKNOWN when only
synthetic JavaScript and local source contracts can be observed.
"""
from __future__ import annotations

import ast
import json
import os
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_SYNTHETIC_ONLY"
STRUCTURE = "LOCAL_STRUCTURE_ONLY"
FILES = {
    "matrix": "docs/matriz-cobertura.md",
    "contrast": "docs/contraste-spec_resto.md",
    "spec": "docs/BRVTAL-SPEC.md",
    "shell": "discadmin/admin-shell.js",
    "css": "discadmin/admin-shell.css",
    "core": "discadmin/index-core.php",
    "ia": "discadmin/admin-information-architecture.js",
    "e2e_shell": "tests/e2e/discadmin-admin-shell.spec.mjs",
    "e2e_ia": "tests/e2e/discadmin-information-architecture.spec.mjs",
}
ROWS = {
    "S44-R009": "The canonical sidebar stays visible on desktop.",
    "S44-R010": "Do not recreate a parallel admin-sidebar.php architecture for Content Core or Security.",
    "S44-R011": "Operational/technical screens should favor useful visual indicators over walls of raw text; raw diagnostics belong behind deliberate advanced controls.",
    "S45-R001": "Sidebar remains persistent while modules replace the central workspace.",
    "S45-R002": "Events → Events workspace;",
}
CANDIDATE = {
    key: ("tests/e2e/discadmin-admin-shell.spec.mjs"
          if key.startswith("S44-") else
          "tests/e2e/discadmin-information-architecture.spec.mjs")
    for key in ROWS
}
EXPECTED = {
    "S44-R009": LOCAL,
    "S44-R010": STRUCTURE,
    "S44-R011": UNKNOWN,
    "S45-R001": STRUCTURE,
    "S45-R002": STRUCTURE,
}

# Execute checked-in JS with a synthetic DOM; no browser, HTTP, DB or login.
NODE_PROBE = r"""
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(process.argv[1], 'utf8');
function classes(initial = []) {
  const set = new Set(initial);
  return {
    contains(name) { return set.has(name); },
    toggle(name, on) {
      if (on === undefined) on = !set.has(name);
      if (on) set.add(name); else set.delete(name);
      return on;
    }
  };
}
let mobile = false;
let change = null;
let navFocus = 0, menuFocus = 0, prevented = false;
const listeners = {};
const menu = {
  attrs: {},
  setAttribute(k, v) { this.attrs[k] = v; },
  focus() { menuFocus++; }
};
const active = {focus() { navFocus++; }};
const shell = {
  classList: classes(['shell']),
  querySelector(selector) {
    if (selector === '.admin-menu-toggle') return menu;
    if (selector === '.nav .active' || selector === '.nav button') return active;
    return null;
  }
};
const document = {
  body: {classList: classes()},
  querySelector(selector) { return selector === '#app .shell' ? shell : null; },
  addEventListener(k, handler) { listeners[k] = handler; }
};
const window = {};
vm.runInNewContext(source, {
  window, document,
  matchMedia() {
    return {
      get matches() { return mobile; },
      addEventListener(k, handler) { if (k === 'change') change = handler; }
    };
  }
}, {filename:'admin-shell.js', timeout:2000});
window.toggleAdminNav();
const desktop = !shell.classList.contains('admin-nav-open')
  && menu.attrs['aria-expanded'] === 'false';
mobile = true;
window.toggleAdminNav();
const mobileOpened = shell.classList.contains('admin-nav-open')
  && document.body.classList.contains('admin-nav-open')
  && menu.attrs['aria-expanded'] === 'true' && navFocus === 1;
listeners.keydown({key:'Escape', preventDefault(){prevented = true;}});
const escaped = prevented && !shell.classList.contains('admin-nav-open')
  && menu.attrs['aria-expanded'] === 'false' && menuFocus === 1;
window.toggleAdminNav();
listeners.click({target:{closest(sel){
  return sel.includes('#app .side .nav button') ? {} : null;
}}});
const clicked = !shell.classList.contains('admin-nav-open')
  && menu.attrs['aria-expanded'] === 'false';
window.toggleAdminNav();
mobile = false;
if (typeof change === 'function') change();
const resized = !shell.classList.contains('admin-nav-open')
  && !document.body.classList.contains('admin-nav-open')
  && menu.attrs['aria-expanded'] === 'false';
window.toggleAdminNav();
const noDesktopOverlay = !shell.classList.contains('admin-nav-open');
console.log(JSON.stringify({
  desktop, mobileOpened, escaped, clicked, resized, noDesktopOverlay
}));
"""


def read_sources() -> dict[str, str]:
    return {k: (ROOT / path).read_text(encoding="utf-8")
            for k, path in FILES.items()}


def rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str or not raw:
        raise ValueError("UNKNOWN: missing matrix")
    found = {}
    for key, clause in ROWS.items():
        matches = [x for x in raw.splitlines() if x.startswith("| " + key + " · ")]
        if len(matches) != 1:
            raise ValueError("UNKNOWN: missing/duplicate " + key)
        cells = tuple(x.strip() for x in matches[0].strip("|").split("|"))
        if (len(cells) != 5 or cells[1] != clause
                or cells[2] != "NO_VERIFICABLE"
                or "Sin aserción contrastada" not in cells[3]
                or CANDIDATE[key] not in cells[3]
                or "[contraste](contraste-spec_resto.md)" not in cells[3]
                or cells[4] != "—"):
            raise ValueError("UNKNOWN: promoted/malformed " + key)
        found[key] = cells
    return found


def contrast_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str or not raw:
        raise ValueError("UNKNOWN: missing contrast")
    result = {}
    for key in ROWS:
        matches = [x for x in raw.splitlines() if x.startswith("| " + key + " |")]
        if len(matches) != 1:
            raise ValueError("UNKNOWN: missing/duplicate contrast " + key)
        cells = tuple(x.strip() for x in matches[0].strip("|").split("|"))
        if (len(cells) != 6 or cells[1] != "NO_VERIFICABLE"
                or CANDIDATE[key] not in cells[3]
                or "no demuestra ausencia funcional" not in cells[5]):
            raise ValueError("UNKNOWN: invalid contrast " + key)
        result[key] = cells
    return result


def observe_shell() -> dict[str, bool]:
    node = shutil.which("node")
    if not node:
        raise AssertionError("UNKNOWN: Node missing")
    result = subprocess.run(
        [node, "-e", NODE_PROBE, str(ROOT / FILES["shell"])],
        cwd=ROOT,
        env={"PATH": os.environ.get("PATH", os.defpath),
             "LANG": "C", "LC_ALL": "C", "HOME": "/nonexistent"},
        capture_output=True, text=True, timeout=15, check=False,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError("UNKNOWN: JS probe failed " + result.stderr[-450:])
    try:
        value = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: malformed JS probe") from exc
    if type(value) is not dict or set(value) != {
        "desktop", "mobileOpened", "escaped", "clicked", "resized",
        "noDesktopOverlay"
    }:
        raise AssertionError("UNKNOWN: partial JS probe")
    return value


def evidence(key: object, src: object, trace: object) -> dict[str, str]:
    result = {"code": UNKNOWN, "browser": UNKNOWN, "production": UNKNOWN}
    if (type(key) is not str or key not in ROWS
            or type(src) is not dict or set(src) != set(FILES)
            or type(trace) is not dict
            or any(type(src[path]) is not str or not src[path] for path in FILES)):
        return result
    try:
        rows(src["matrix"])
        contrast_rows(src["contrast"])
    except ValueError:
        return result
    spec = src["spec"].replace(chr(96), "")
    if ("## 44. DISCADMIN UX" not in spec
            or "## 45. Workspace behavior" not in spec
            or ROWS[key] not in spec
            or set(trace) != {"desktop", "mobileOpened", "escaped", "clicked",
                              "resized", "noDesktopOverlay"}
            or not all(type(value) is bool and value is True
                       for value in trace.values())):
        return result

    core, ia, css = src["core"], src["ia"], src["css"]
    if (core.count('<aside class="side" id="admin-primary-nav"') != 1
            or '<main class="main">' not in core
            or "const previousSide=root.querySelector('.side');" not in core
            or "root.querySelector('.side').replaceWith(previousSide);" not in core):
        return result

    if key == "S44-R009":
        if (".shell{grid-template-columns:252px minmax(0,1fr)}" in css
                and "@media(max-width:850px)" in css
                and ".side{position:fixed;" in css
                and "position:sticky;top:0;height:100vh" in core
                and "desktop sidebar stays visible" in src["e2e_shell"]
                and "await expect(nav).toBeVisible()" in src["e2e_shell"]):
            result["code"] = LOCAL
    elif key == "S44-R010":
        if (not (ROOT / "discadmin/admin-sidebar.php").exists()
                and "admin-sidebar.php" not in core
                and "admin-sidebar.php" not in ia
                and 'id="admin-module-host"' in core
                and "'content-core'" in core and "'security'" in core):
            result["code"] = STRUCTURE
    elif key == "S44-R011":
        # Four status cards exist, but the raw SYSTEM RUNTIME details are
        # default-open and visual prioritization is not proved by source text.
        if ("system-status-grid" not in core
                or core.count("system-status-card") < 4
                or "API TESTER — ADVANCED" not in core
                or '<details class="system-detail" open>' not in core):
            return result
    elif key == "S45-R001":
        if ("window.go = async function(section)" in ia
                and "restoreVisibleSection(visibleSection);" in ia
                and "rebuildNavigation();" in ia
                and "Events is the single entry to the guided event editor"
                    in src["e2e_ia"]):
            result["code"] = STRUCTURE
    elif key == "S45-R002":
        if ("if (section === 'events')" in ia
                and "const visibleSection = 'events';" in ia
                and "await invokeOriginalGo(visibleSection, token)" in ia
                and "await window.BRVTALAdminModules.load('content-core'"
                    in ia
                and "await page.evaluate(() => window.go('events'))"
                    in src["e2e_ia"]
                and "data-canonical-events-list" in src["e2e_ia"]):
            result["code"] = STRUCTURE
    return result


class BrechasBrvtal71Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.src = read_sources()
        cls.trace = observe_shell()

    def test_contrato_principal(self) -> None:
        self.assertEqual(set(rows(self.src["matrix"])), set(ROWS))
        self.assertEqual(set(contrast_rows(self.src["contrast"])), set(ROWS))
        self.assertTrue(all(value is True for value in self.trace.values()))
        for key in ROWS:
            with self.subTest(key=key):
                self.assertEqual(EXPECTED[key], evidence(key, self.src, self.trace)["code"])

    def test_falla_cerrado(self) -> None:
        for key in ROWS:
            row = next(x for x in self.src["matrix"].splitlines()
                       if x.startswith("| " + key + " · "))
            for bad in (
                self.src["matrix"].replace(row, "", 1),
                self.src["matrix"] + "\n" + row,
                self.src["matrix"].replace(row, row.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1),
            ):
                with self.subTest(key=key), self.assertRaisesRegex(ValueError, UNKNOWN):
                    rows(bad)
                self.assertEqual(UNKNOWN, evidence(
                    key, {**self.src, "matrix": bad}, self.trace)["code"])
            self.assertEqual(UNKNOWN, evidence(key, {}, self.trace)["code"])
            self.assertEqual(UNKNOWN, evidence(key, self.src, {})["code"])
            contrast = next(x for x in self.src["contrast"].splitlines()
                            if x.startswith("| " + key + " |"))
            self.assertEqual(UNKNOWN, evidence(
                key, {**self.src, "contrast":
                      self.src["contrast"].replace(contrast, "", 1)},
                self.trace)["code"])
        wrong = {**self.trace, "escaped": False}
        self.assertEqual(UNKNOWN, evidence("S44-R009", self.src, wrong)["code"])
        self.assertEqual(UNKNOWN, evidence("S45-R002",
                                          {**self.src, "ia": ""}, self.trace)["code"])

    def test_sin_datos_reales(self) -> None:
        own = Path(__file__).read_text(encoding="utf-8")
        imported = set()
        for node in ast.walk(ast.parse(own)):
            if isinstance(node, ast.Import):
                imported.update(x.name.split(".")[0] for x in node.names)
            if isinstance(node, ast.ImportFrom) and node.module:
                imported.add(node.module.split(".")[0])
        self.assertFalse(imported & {"requests", "urllib", "socket", "httpx",
                                     "mysql", "pymysql", "playwright", "selenium"})
        for forbidden in ("node:net", "node:http", "node:https", "fetch(",
                          "XMLHttpRequest", "new WebSocket", "process.env",
                          "child_process", "new PDO"):
            self.assertNotIn(forbidden, NODE_PROBE)
        self.assertIn("node:vm", NODE_PROBE)

    def test_estados_unknown_explicitos(self) -> None:
        for key in ROWS:
            with self.subTest(key=key):
                result = evidence(key, self.src, self.trace)
                self.assertEqual("NO_VERIFICABLE", rows(self.src["matrix"])[key][2])
                self.assertEqual(UNKNOWN, result["browser"])
                self.assertEqual(UNKNOWN, result["production"])
        self.assertEqual(UNKNOWN, evidence("S44-R011", self.src, self.trace)["code"])
        self.assertEqual(UNKNOWN, evidence(
            "S44-R009", self.src, {**self.trace, "production": True})["code"])


if __name__ == "__main__":
    unittest.main()
