#!/usr/bin/env python3
"""BRVTAL #1148: offline and fail-closed S39B-R001..R005 data-grid evidence.

Runs the real shared JavaScript in a network-disabled, synthetic Node VM;
not evidence of login, browser E2E, CI, deployment or production.
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
GRID = "discadmin/admin-data-grid.js"
STYLE = "discadmin/admin-data-grid.css"
SHELL = "discadmin/index.php"
BACKEND = "config/admin_grid.php"
FILES = (MATRIX, SPEC, GRID, STYLE, SHELL, BACKEND)
IDS = tuple(f"S39B-R{i:03d}" for i in range(1, 6))
CLAUSES = {
    "S39B-R001": "Major record lists use one shared professional data-grid interaction model for Events, Artists, Releases, Sets, Media, Pages and Blog.",
    "S39B-R002": "The canonical shared layer is discadmin/admin-data-grid.js + discadmin/admin-data-grid.css.",
    "S39B-R003": "Modules retain their own editorial actions and filters, but must not reintroduce bespoke table/card interaction models for record management.",
    "S39B-R004": "Grid behavior:",
    "S39B-R005": "sortable columns use a tri-state cycle: ascending → descending → module default;",
}
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_SYNTHETIC_ONLY"
GAP = "BRECHA_DOCUMENTADA"

# No browser, network, DB or environment inheritance: the actual JS source runs
# within a minimal VM with synthetic, inert DOM nodes and an inert fetch stub.
NODE_PROBE = r"""
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(process.argv[1], 'utf8');
const context = {
  window: {},
  document: { querySelectorAll: () => [] },
  CSS: { escape: value => String(value) },
  fetch: () => new Promise(() => {}),
  queueMicrotask: () => {},
  requestAnimationFrame: () => {},
  console: { error() {} },
};
vm.runInNewContext(source, context, { timeout: 2500, filename: 'admin-data-grid.js' });
const grid = context.window.BRVTALDataGrid;
if (!grid || typeof grid.render !== 'function') process.exit(4);
const modules = ['events','artists','releases','sets','media','pages','blog'];
function host() {
  return {
    isConnected: true,
    innerHTML: '',
    handlers: new Map(),
    querySelector: () => null,
    querySelectorAll(selector) {
      if (selector !== '[data-grid-sort]') return [];
      this.handlers.clear();
      return [...this.innerHTML.matchAll(/data-grid-sort="([^"]+)"/g)].map(m => ({
        dataset: { gridSort: m[1] },
        addEventListener: (event, cb) => { if (event === 'click') this.handlers.set(m[1], cb); }
      }));
    }
  };
}
const supported = modules.map(module => grid.supports(module) === true);
const rendered = {};
for (const module of modules) {
  const h = host();
  const ok = grid.render(module, h, [{ id: 1, title: 'synthetic', name: 'synthetic' }]);
  rendered[module] = {ok, html:h.innerHTML};
}
const h = host();
const rows = [
  { id:3, name:'Beta', is_collective_member:1 },
  { id:2, name:'Alpha', is_collective_member:0 },
  { id:1, name:'Beta', is_collective_member:1 }
];
if (!grid.render('artists', h, rows)) process.exit(5);
const snapshot = () => ({
  ids:[...h.innerHTML.matchAll(/data-grid-row-id="(\d+)"/g)].map(m => Number(m[1])),
  sort:[...h.innerHTML.matchAll(/aria-sort="([^"]+)"/g)].map(m => m[1]),
  ordering:/data-order-enabled="([01])"/.exec(h.innerHTML)?.[1] ?? null
});
const states = [snapshot()];
for (let i=0;i<3;i++) {
  const click = h.handlers.get('primary');
  if (typeof click !== 'function') process.exit(6);
  click();
  states.push(snapshot());
}
const invalid = grid.supports('synthetic-module') === false
  && grid.render('synthetic-module', host(), []) === false;
console.log(JSON.stringify({ supported, invalid, rendered, states }));
"""


def rows(raw: object) -> dict[str, tuple[str, ...]]:
    """Only five complete, unique rows with their original gap state pass."""
    if type(raw) is not str or not raw.strip():
        raise ValueError(UNKNOWN + ": missing matrix")
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        match = re.match(r"^\| (S39B-R00[1-5]) · ", line)
        if match is None:
            continue
        key = match.group(1)
        cells = tuple(part.strip() for part in line.strip("|").split("|"))
        if (key in found or len(cells) != 5
                or not cells[0].startswith(f"{key} · [S39B L")
                or "docs/BRVTAL-SPEC.md#L" not in cells[0]
                or cells[1] != CLAUSES[key] or cells[2] != "NO_VERIFICABLE"
                or "Sin aserción contrastada para «" not in cells[3]
                or "falta enlazar prueba de ese inciso, archivo de código y PR fusionado" not in cells[3]
                or cells[4] != "—"):
            raise ValueError(UNKNOWN + ": invalid " + key)
        found[key] = cells
    if set(found) != set(IDS):
        raise ValueError(UNKNOWN + ": missing/duplicate rows")
    return found


def sources() -> dict[str, str]:
    return {name: (ROOT / name).read_text(encoding="utf-8") for name in FILES}


def observe_grid() -> dict[str, object]:
    node = shutil.which("node")
    if node is None:
        raise AssertionError(UNKNOWN + ": node runtime unavailable")
    result = subprocess.run(
        [node, "-e", NODE_PROBE, str(ROOT / GRID)],
        cwd=ROOT,
        env={"PATH": os.defpath, "LANG": "C", "LC_ALL": "C", "HOME": "/nonexistent"},
        text=True, capture_output=True, timeout=15, check=False,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError(UNKNOWN + ": synthetic grid failed " + result.stderr[-400:])
    data = json.loads(result.stdout)
    if type(data) is not dict or set(data) != {"supported", "invalid", "rendered", "states"}:
        raise ValueError(UNKNOWN + ": malformed grid observation")
    return data


def evidence(key: object, src: object, observed: object) -> str:
    """Source/runtime witnesses are partial; never promote to production."""
    if type(key) is not str or key not in IDS or type(src) is not dict or type(observed) is not dict:
        return UNKNOWN
    if set(src) != set(FILES) or any(type(src[p]) is not str or not src[p] for p in FILES):
        return UNKNOWN
    try:
        rows(src[MATRIX])
    except ValueError:
        return UNKNOWN
    spec = src[SPEC].split("## 39. Canonical DISCADMIN data grid", 1)
    if len(spec) != 2 or CLAUSES[key] not in spec[1]:
        return UNKNOWN
    expected = ("events", "artists", "releases", "sets", "media", "pages", "blog")
    rendered = observed.get("rendered")
    if (observed.get("supported") != [True] * 7 or observed.get("invalid") is not True
            or type(rendered) is not dict or set(rendered) != set(expected)):
        return UNKNOWN
    if not all(type(rendered[module]) is dict and rendered[module].get("ok") is True
               and f'data-grid-module="{module}"' in rendered[module].get("html", "")
               for module in expected):
        return UNKNOWN
    if key == "S39B-R001":
        return LOCAL
    if key == "S39B-R002":
        return LOCAL if ("/discadmin/admin-data-grid.js" in src[SHELL]
                and "/discadmin/admin-data-grid.css" in src[SHELL]
                and ".admin-data-grid" in src[STYLE]
                and "window.BRVTALDataGrid = {render,clearSelection,supports,defaultColumns}" in src[GRID]) else UNKNOWN
    if key == "S39B-R003":
        return LOCAL if (all(tag in rendered["events"]["html"] for tag in (
                    'data-grid-action="lineup"', 'data-grid-action="edit"', 'data-grid-action="delete"'))
                and 'data-grid-action="details"' in rendered["media"]["html"]
                and 'data-grid-action="delete"' not in rendered["media"]["html"]
                and "data-grid-membership-filter" in rendered["artists"]["html"]
                and "function actionsFor(module)" in src[GRID]) else UNKNOWN
    if key == "S39B-R004":
        # Structural introductory heading, not an independently verifiable AC.
        return GAP
    states = observed.get("states")
    if type(states) is not list or len(states) != 4:
        return UNKNOWN
    return LOCAL if (
        [state.get("ids") for state in states]
        == [[3, 2, 1], [2, 1, 3], [1, 3, 2], [3, 2, 1]]
        and [state.get("ordering") for state in states] == ["1", "0", "0", "1"]
        and [state.get("sort", [None])[0] for state in states]
        == ["none", "ascending", "descending", "none"]
    ) else UNKNOWN


def operational(_key: object, _claim: object = None) -> str:
    return UNKNOWN


class BrechasBrvtal58Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.src = sources()
        cls.observed = observe_grid()

    def test_contrato_principal(self) -> None:
        self.assertEqual(set(rows(self.src[MATRIX])), set(IDS))
        expected = {**dict.fromkeys(IDS, LOCAL), "S39B-R004": GAP}
        for key in IDS:
            with self.subTest(id=key):
                self.assertEqual(expected[key], evidence(key, self.src, self.observed))
                self.assertEqual(UNKNOWN, operational(key, self.observed))

    def test_falla_cerrado(self) -> None:
        raw = self.src[MATRIX]
        for key in IDS:
            line = next(x for x in raw.splitlines() if x.startswith(f"| {key} · "))
            for invalid in (raw.replace(line, "", 1), raw + "\n" + line,
                            raw.replace(line, line.replace("NO_VERIFICABLE", "VERIFICADO"), 1)):
                with self.subTest(id=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        rows(invalid)
                    self.assertEqual(UNKNOWN, evidence(key, {**self.src, MATRIX: invalid}, self.observed))
            self.assertEqual(UNKNOWN, evidence(key, {**self.src, GRID: ""}, self.observed))
            self.assertEqual(UNKNOWN, evidence(key, self.src, {}))
        changed = json.loads(json.dumps(self.observed))
        changed["states"][2]["ids"] = [1, 2, 3]
        self.assertEqual(UNKNOWN, evidence("S39B-R005", self.src, changed))
        self.assertEqual(UNKNOWN, evidence(None, self.src, self.observed))

    def test_sin_datos_reales(self) -> None:
        code = Path(__file__).read_text(encoding="utf-8")
        imported: set[str] = set()
        for node in ast.walk(ast.parse(code)):
            if isinstance(node, ast.Import):
                imported.update(x.name.split(".")[0] for x in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                imported.add(node.module.split(".")[0])
        self.assertLessEqual(imported, {"__future__", "ast", "json", "os", "pathlib", "re", "shutil", "subprocess", "unittest"})
        self.assertIn("fetch: () => new Promise(() => {})", NODE_PROBE)
        self.assertNotIn("require('node:http')", NODE_PROBE)
        self.assertNotIn("require('node:https')", NODE_PROBE)
        self.assertNotIn("DATABASE_URL", NODE_PROBE)
        self.assertNotIn("GITHUB_TOKEN", NODE_PROBE)

    def test_estados_unknown_explicitos(self) -> None:
        for key in IDS:
            with self.subTest(id=key):
                for claimed in (None, {}, True, {"CI": "success"},
                                {"live": True}, {"production": "GREEN"}):
                    self.assertEqual(UNKNOWN, operational(key, claimed))
                self.assertEqual("NO_VERIFICABLE", rows(self.src[MATRIX])[key][2])
        self.assertEqual(UNKNOWN, evidence("S39B-R999", self.src, self.observed))


if __name__ == "__main__":
    unittest.main()
