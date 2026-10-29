#!/usr/bin/env python3
"""#1151: five synthetic BRVTAL grid/schema contracts, fail closed.

Runs checked-in JS in an inert Node VM and PHP route parser with fake URIs.
No browser E2E, SQL connection, authentication, deployment, or production proof.
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
FILES = {
    "matrix": "docs/matriz-cobertura.md",
    "spec": "docs/BRVTAL-SPEC.md",
    "grid": "discadmin/admin-data-grid.js",
    "css": "discadmin/admin-data-grid.css",
    "schema": "database/schema.sql",
    "route": "api/route.php",
    "api": "api/index.php",
    "auth": "config/admin_auth.php",
}
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_SYNTHETIC_ONLY"
GAP = "BRECHA_DOCUMENTADA"
TICK = chr(96)
TABLES = {
    "S40-L623": "admins",
    "S40-L624": "events",
    "S40-L625": "artists",
    "S40-L627": "sets_media",
}
CLAUSES = {
    "S39B-R016": "The shared grid must remain keyboard/focus accessible and horizontally usable on narrow/mobile viewports.",
    **{key: TICK + name + TICK + ";" for key, name in TABLES.items()},
}
IDS = tuple(CLAUSES)

# The host is a minimal, inert fake DOM; no browser/network/real administrator.
NODE_PROBE = r"""
const fs = require('node:fs'), vm = require('node:vm');
const source = fs.readFileSync(process.argv[1], 'utf8');
const focus = [], microtasks = [];
const ctx = {
  window: {}, document: {querySelectorAll:()=>[]},
  CSS: {escape: v=>String(v)}, fetch: ()=>new Promise(()=>{}),
  queueMicrotask: fn=>microtasks.push(fn), requestAnimationFrame: ()=>{},
  console: {error(){}},
};
vm.runInNewContext(source, ctx, {timeout:2500, filename:'admin-data-grid.js'});
const grid = ctx.window.BRVTALDataGrid;
if (!grid || typeof grid.render !== 'function') process.exit(3);
function host() {
  return {
    isConnected:true, innerHTML:'', handlers:new Map(),
    querySelector(selector) {
      return selector.startsWith('[data-grid-sort="')
        ? {focus:()=>focus.push(selector)} : null;
    },
    querySelectorAll(selector) {
      if (selector !== '[data-grid-sort]') return [];
      this.handlers.clear();
      return [...this.innerHTML.matchAll(/data-grid-sort="([^"]+)"/g)].map(m=>({
        dataset:{gridSort:m[1]},
        addEventListener:(event, cb)=>{
          if(event==='click') this.handlers.set(m[1],cb);
        },
      }));
    },
  };
}
const h = host();
const rendered = grid.render('artists',h,[
  {id:3,name:'Beta',is_collective_member:1},
  {id:2,name:'Alpha',is_collective_member:0},
]);
const initial=h.innerHTML;
const click=h.handlers.get('primary');
if(typeof click!=='function') process.exit(4);
click();
while(microtasks.length) microtasks.shift()();
console.log(JSON.stringify({
  rendered,
  roleTable:initial.includes('role="table" aria-label="artists records"'),
  rows:initial.includes('role="row"') && initial.includes('role="rowgroup"')
    && initial.includes('role="columnheader"') && initial.includes('role="cell"'),
  keyboard:/<button[^>]+data-grid-sort="primary"/.test(initial)
    && initial.includes('data-grid-select-all aria-label="Select all current results"'),
  chooser:initial.includes('data-grid-columns-toggle aria-expanded="false"'),
  scroll:initial.includes('class="admin-data-grid-scroll"'),
  sort:h.innerHTML.includes('aria-sort="ascending"'),
  focus:focus.includes('[data-grid-sort="primary"]'),
  unknown: grid.render('not-a-module',host(),[])===false,
}));
"""
EXPECTED_GRID = dict.fromkeys(("rendered", "roleTable", "rows", "keyboard",
                               "chooser", "scroll", "sort", "focus", "unknown"), True)

PHP_PROBE = r"""
require $argv[1];
$cases=[
 'event'=>['/api/events/7','GET'], 'artist'=>['/api/artists/8','GET'],
 'set'=>['/api/sets/9','GET'], 'bad_event'=>['/api/events/no','GET'],
 'bad_artist'=>['/api/artists/8/extra','GET'],
 'bad_set'=>['/api/sets/no','GET'],
];
$out=[];
foreach($cases as $key=>[$uri,$method]) {
  $r=brvtal_api_parse_route($uri,'/api/index.php',$method);
  $out[$key]=[$r['resource'],$r['id'],$r['action']];
}
echo json_encode($out,JSON_THROW_ON_ERROR);
"""
EXPECTED_ROUTES = {
    "event": ["events", 7, "7"],
    "artist": ["artists", 8, "8"],
    "set": ["sets", 9, "9"],
    "bad_event": ["", None, ""],
    "bad_artist": ["", None, ""],
    "bad_set": ["", None, ""],
}


def sources() -> dict[str, str]:
    return {key: (ROOT / file).read_text(encoding="utf-8")
            for key, file in FILES.items()}


def rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str or not raw:
        raise ValueError("UNKNOWN: missing matrix")
    found = {}
    for line in raw.splitlines():
        m = re.match(r"^\| (S39B-R016|S40-L62[3457]) · ", line)
        if not m:
            continue
        key = m.group(1)
        cells = tuple(part.strip() for part in line.strip("|").split("|"))
        if (key in found or len(cells) != 5
                or not cells[0].startswith(key + " · [")
                or "docs/BRVTAL-SPEC.md#L" not in cells[0]
                or cells[1] != CLAUSES[key] or cells[2] != "NO_VERIFICABLE"
                or "Sin aserción contrastada para «" not in cells[3]
                or "falta enlazar prueba de ese inciso, archivo de código y PR fusionado" not in cells[3]
                or "contraste-spec_resto.md" not in cells[3]
                or cells[4] != "—"):
            raise ValueError("UNKNOWN: duplicate/malformed/promoted " + key)
        found[key] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: missing rows")
    return found


def ddl(schema: object, name: str) -> str | None:
    if type(schema) is not str:
        return None
    blocks = re.findall(
        rf"(?ms)^CREATE TABLE {re.escape(name)} \(\n(.*?)^\) ENGINE=InnoDB;",
        schema,
    )
    return blocks[0] if len(blocks) == 1 else None


def env_isolated(home: str) -> dict[str, str]:
    return {"PATH": os.defpath, "HOME": home, "LANG": "C", "APP_ENV": "testing"}


def observe() -> dict[str, object]:
    if shutil.which("node") is None or shutil.which("php") is None:
        raise AssertionError("UNKNOWN: offline Node/PHP unavailable")
    with tempfile.TemporaryDirectory(prefix="brvtal-1151-") as home:
        env = env_isolated(home)
        node = subprocess.run(
            ["node", "-e", NODE_PROBE, str(ROOT / FILES["grid"])],
            env=env, cwd=ROOT, capture_output=True, text=True, timeout=20,
            check=False,
        )
        php = subprocess.run(
            ["php", "-d", "allow_url_fopen=0", "-r", PHP_PROBE,
             str(ROOT / FILES["route"])],
            env=env, cwd=ROOT, capture_output=True, text=True, timeout=15,
            check=False,
        )
    if node.returncode or php.returncode or node.stderr or php.stderr:
        raise AssertionError("UNKNOWN: synthetic execution failed")
    try:
        grid, routes = json.loads(node.stdout), json.loads(php.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: invalid output") from exc
    if (type(grid) is not dict or set(grid) != set(EXPECTED_GRID)
            or type(routes) is not dict or set(routes) != set(EXPECTED_ROUTES)):
        raise AssertionError("UNKNOWN: incomplete output")
    return {"grid": grid, "routes": routes}


def evidence(key: object, data: object, observed: object) -> str:
    if (type(key) is not str or key not in IDS or type(data) is not dict
            or set(data) != set(FILES) or type(observed) is not dict
            or any(type(data[n]) is not str or not data[n] for n in FILES)
            or observed != {"grid": EXPECTED_GRID, "routes": EXPECTED_ROUTES}):
        return UNKNOWN
    try:
        rows(data["matrix"])
    except ValueError:
        return UNKNOWN
    spec = data["spec"]
    if "## 40. Current database model" not in spec:
        return UNKNOWN
    if key == "S39B-R016":
        css, js = data["css"], data["grid"]
        return LOCAL if all((
            CLAUSES[key] in spec,
            ".admin-data-grid-scroll{width:100%;overflow:auto" in css,
            ".admin-data-grid{min-width:780px}" in css,
            "@media(max-width:760px)" in css,
            ".admin-data-grid{min-width:720px}" in css,
            ".admin-grid-sort:hover,.admin-grid-sort:focus-visible" in css,
            "function restoreFocus(state)" in js,
            "state.host.querySelector(selector)?.focus()" in js,
        )) else UNKNOWN
    name = TABLES[key]
    block = ddl(data["schema"], name)
    if (block is None or "- " + TICK + name + TICK + ";" not in spec
            or "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY" not in block):
        return UNKNOWN
    api = data["api"]
    if key == "S40-L623":
        # A real auth database is not exercisable offline; document the gap.
        return GAP if all((
            "email VARCHAR(190) NOT NULL UNIQUE" in block,
            "password_hash VARCHAR(255) NOT NULL" in block,
            "FROM admins WHERE email=? AND is_active=1" in api,
            "function brvtal_admin_require(): void" in data["auth"],
        )) else UNKNOWN
    return LOCAL if all((
        "slug VARCHAR(190) NOT NULL UNIQUE" in block,
        "status ENUM(" in block,
        "function table_for(string $resource): string" in api,
        "'events'=>'events','artists'=>'artists','sets'=>'sets_media'" in api,
        {"S40-L624": "event", "S40-L625": "artist",
         "S40-L627": "set"}[key] in observed["routes"],
    )) else UNKNOWN


def operational(_key: object, _claim: object = None) -> str:
    return UNKNOWN


class BrechasBrvtal61Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.src = sources()
        cls.observed = observe()

    def test_contrato_principal(self) -> None:
        self.assertEqual(set(IDS), set(rows(self.src["matrix"])))
        for key in IDS:
            with self.subTest(clause=key):
                expected = GAP if key == "S40-L623" else LOCAL
                self.assertEqual(expected, evidence(key, self.src, self.observed))
                self.assertEqual(UNKNOWN, operational(key, self.observed))
        self.assertEqual(EXPECTED_GRID, self.observed["grid"])
        self.assertEqual(EXPECTED_ROUTES, self.observed["routes"])

    def test_falla_cerrado(self) -> None:
        raw = self.src["matrix"]
        for key in IDS:
            original = next(x for x in raw.splitlines()
                            if x.startswith("| " + key + " · "))
            for broken in (raw.replace(original, "", 1), raw + "\n" + original,
                           raw.replace(original, original.replace(
                               "NO_VERIFICABLE", "VERIFICADO"), 1)):
                with self.subTest(clause=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        rows(broken)
                    self.assertEqual(UNKNOWN, evidence(
                        key, {**self.src, "matrix": broken}, self.observed))
            self.assertEqual(UNKNOWN, evidence(key, self.src, {}))
            self.assertEqual(UNKNOWN, evidence(
                key, {**self.src, "spec": "missing"}, self.observed))
        for name in FILES:
            incomplete = dict(self.src)
            incomplete.pop(name)
            self.assertEqual(UNKNOWN, evidence(IDS[0], incomplete, self.observed))
        changed = json.loads(json.dumps(self.observed))
        changed["grid"]["focus"] = False
        self.assertEqual(UNKNOWN, evidence("S39B-R016", self.src, changed))
        changed = json.loads(json.dumps(self.observed))
        changed["routes"]["bad_artist"] = ["artists", 8, "extra"]
        self.assertEqual(UNKNOWN, evidence("S40-L625", self.src, changed))
        self.assertEqual(UNKNOWN, evidence(
            "S40-L627", {**self.src, "schema": self.src["schema"].replace(
                "CREATE TABLE sets_media (", "CREATE TABLE different (", 1
            )}, self.observed))
        self.assertEqual(UNKNOWN, evidence(
            "S39B-R016", {**self.src, "css": ".admin-data-grid{min-width:0}"},
            self.observed))
        self.assertEqual(UNKNOWN, evidence(None, self.src, self.observed))

    def test_sin_datos_reales(self) -> None:
        tree = ast.parse(Path(__file__).read_text(encoding="utf-8"))
        names = {item.name.split(".")[0] for node in ast.walk(tree)
                 if isinstance(node, ast.Import) for item in node.names}
        names |= {node.module.split(".")[0] for node in ast.walk(tree)
                  if isinstance(node, ast.ImportFrom) and node.module}
        self.assertFalse(names & {"requests", "httpx", "socket", "urllib",
                                  "pymysql", "playwright"})
        self.assertNotIn("PDO(", PHP_PROBE)
        with tempfile.TemporaryDirectory(prefix="brvtal-1151-env-") as home:
            env = env_isolated(home)
            for name in ("DB_PASSWORD", "DATABASE_URL", "MYSQL_PWD", "GH_TOKEN",
                         "GITHUB_TOKEN", "HTTP_PROXY", "HTTPS_PROXY"):
                self.assertNotIn(name, env)
        self.assertEqual(set(FILES), set(self.src))
        self.assertEqual({"grid", "routes"}, set(self.observed))

    def test_estados_unknown_explicitos(self) -> None:
        for key in IDS:
            with self.subTest(clause=key):
                for fake in (None, True, {"ci": "success"},
                             {"production": "GREEN"}, {"migration": "applied"}):
                    self.assertEqual(UNKNOWN, operational(key, fake))
                self.assertEqual("NO_VERIFICABLE", rows(self.src["matrix"])[key][2])
        self.assertEqual(GAP, evidence("S40-L623", self.src, self.observed))
        self.assertIsNone(ddl(None, "admins"))


if __name__ == "__main__":
    unittest.main()
