#!/usr/bin/env python3
"""BRVTAL #1150: offline S39B-R011..015 tests, no live readiness claim.

Run the committed PHP bulk request normalizer and canonical JS grid against
synthetic records. A static route or fixture cannot prove real DB transactions,
browser behavior, deployment or that a legacy UI fallback was removed.
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
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/BRVTAL-SPEC.md"
GRID = "discadmin/admin-data-grid.js"
ORDER = "discadmin/content-ordering.js"
BULK = "discadmin/bulk-actions.js"
BULK_LIB = "api/bulk-actions-lib.php"
BULK_API = "api/bulk-actions.php"
MEDIA = "discadmin/media-library.js"
SHELL = "discadmin/index.php"
FILES = (MATRIX, SPEC, GRID, ORDER, BULK, BULK_LIB, BULK_API, MEDIA, SHELL)
CLAUSES = {
    "S39B-R011": "modules with safe status bulk actions reuse the existing transactional Bulk Actions engine rather than implementing a second mutation path;",
    "S39B-R012": "destructive bulk delete remains out of scope;",
    "S39B-R013": "Artists, Sets, Releases and Blog keep visual ordering; drag/drop ordering is disabled whenever an explicit grid sort or partial filtered view is active;",
    "S39B-R014": "Releases Catalog and Blog render through the same canonical grid pattern as the core record lists;",
    "S39B-R015": "Media keeps its uploader and inspector around the canonical record table rather than maintaining a separate record-card interaction model.",
}
IDS = tuple(CLAUSES)
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_SYNTHETIC_ONLY"
GAP = "BRECHA_DOCUMENTADA"

PHP_PROBE = r"""
require $argv[1];
$valid = [];
foreach (['events','artists','sets','pages','releases','blog'] as $name) {
    $spec = brvtal_bulk_resource_specs()[$name];
    $valid[$name] = brvtal_bulk_normalize_request([
        'resource'=>$name, 'action'=>'set_status',
        'status'=>$spec['statuses'][0], 'ids'=>[7,8,7]
    ]);
}
$invalid = [];
foreach ([
    'delete'=>['resource'=>'artists','action'=>'delete','status'=>'draft','ids'=>[7]],
    'bulk_delete'=>['resource'=>'blog','action'=>'bulk_delete','status'=>'draft','ids'=>[7]],
    'media'=>['resource'=>'media','action'=>'set_status','status'=>'published','ids'=>[7]],
    'bad_status'=>['resource'=>'artists','action'=>'set_status','status'=>'unknown','ids'=>[7]],
    'bad_ids'=>['resource'=>'artists','action'=>'set_status','status'=>'draft','ids'=>[0]],
    'too_many'=>['resource'=>'artists','action'=>'set_status','status'=>'draft','ids'=>range(1,101)],
] as $name=>$payload) {
    try { brvtal_bulk_normalize_request($payload); $invalid[$name] = 'ACCEPTED'; }
    catch (InvalidArgumentException $e) { $invalid[$name] = $e->getMessage(); }
}
echo json_encode(['valid'=>$valid,'invalid'=>$invalid], JSON_THROW_ON_ERROR);
"""

NODE_PROBE = r"""
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(process.argv[1], 'utf8');
const ctx = {
  window:{BRVTALBulkActions:{supports:m=>m !== 'media',open(){}}},
  document:{querySelectorAll:()=>[]},
  CSS:{escape:x=>String(x)},
  fetch:()=>new Promise(()=>{}),
  queueMicrotask:()=>{},requestAnimationFrame:()=>{},
  console:{error(){}}
};
vm.runInNewContext(source,ctx,{timeout:2500,filename:'admin-data-grid.js'});
const grid=ctx.window.BRVTALDataGrid;
if (!grid || typeof grid.render !== 'function') process.exit(3);
function host() {
  return {isConnected:true,innerHTML:'',sorts:new Map(),querySelector:()=>null,
    querySelectorAll(selector) {
      if (selector !== '[data-grid-sort]') return [];
      this.sorts.clear();
      return [...this.innerHTML.matchAll(/data-grid-sort="([^"]+)"/g)].map(m=>({
        dataset:{gridSort:m[1]},
        addEventListener:(name,fn)=>{if(name==='click') this.sorts.set(m[1],fn);}
      }));
    }
  };
}
function check(module,options={}) {
  const h=host();
  const yes=grid.render(module,h,[
    {id:2,name:'Bravo',title:'Bravo',status:'draft',sort_order:2},
    {id:1,name:'Alpha',title:'Alpha',status:'published',sort_order:1},
  ],options);
  return {yes,html:h.innerHTML,h};
}
const captured={};
for(const module of ['artists','sets','releases','blog','media']){
  const current=check(module);
  captured[module]={
    yes:current.yes,
    table:current.html.includes('role="table"'),
    module:current.html.includes('data-grid-module="'+module+'"'),
    rows:(current.html.match(/data-grid-row-id="/g)||[]).length,
    enabled:/data-order-enabled="([01])"/.exec(current.html)?.[1] ?? null
  };
}
const sorted={};
for(const module of ['artists','sets','releases','blog']){
  const current=check(module);
  const click=current.h.sorts.get('primary');
  if(typeof click !== 'function') process.exit(4);
  click();
  sorted[module]=/data-order-enabled="([01])"/.exec(current.h.innerHTML)?.[1] ?? null;
}
const limited={};
for(const module of ['artists','sets','releases','blog']){
  const current=check(module,{orderingEnabled:false});
  limited[module]=/data-order-enabled="([01])"/.exec(current.html)?.[1] ?? null;
}
console.log(JSON.stringify({captured,sorted,limited,unknown:grid.supports('not-a-module')}));
"""


def read_sources() -> dict[str, str]:
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in FILES}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str or not raw:
        raise ValueError(UNKNOWN + ": matrix missing")
    found = {}
    for line in raw.splitlines():
        m = re.match(r"^\| (S39B-R01[1-5]) · ", line)
        if not m:
            continue
        key = m.group(1)
        parts = tuple(part.strip() for part in line.strip("|").split("|"))
        if (key in found or len(parts) != 5
                or not parts[0].startswith(key + " · [S39B L")
                or "docs/BRVTAL-SPEC.md#L" not in parts[0]
                or parts[1] != CLAUSES[key] or parts[2] != "NO_VERIFICABLE"
                or "Sin aserción contrastada para «" not in parts[3]
                or "falta enlazar prueba de ese inciso, archivo de código y PR fusionado" not in parts[3]
                or parts[4] != "—"):
            raise ValueError(UNKNOWN + ": altered " + key)
        found[key] = parts
    if set(found) != set(IDS):
        raise ValueError(UNKNOWN + ": missing/duplicated rows")
    return found


def isolated_env(home: str) -> dict[str, str]:
    return {"PATH": os.defpath, "HOME": home, "LANG": "C", "LC_ALL": "C"}


def run_offline(command: list[str]) -> dict[str, object]:
    with tempfile.TemporaryDirectory(prefix="brvtal-1150-synthetic-") as home:
        p = subprocess.run(command, cwd=ROOT, env=isolated_env(home),
                           capture_output=True, text=True, timeout=20, check=False)
    if p.returncode or p.stderr.strip():
        raise AssertionError(UNKNOWN + ": offline probe failed: " + p.stderr[-240:])
    try:
        data = json.loads(p.stdout)
    except ValueError as e:
        raise AssertionError(UNKNOWN + ": malformed offline probe") from e
    if type(data) is not dict:
        raise AssertionError(UNKNOWN + ": probe returned non-object")
    return data


def observe() -> dict[str, object]:
    php, node = shutil.which("php"), shutil.which("node")
    if not php or not node:
        raise AssertionError(UNKNOWN + ": missing PHP/Node CLI")
    return {
        "bulk": run_offline([php, "-r", PHP_PROBE, str(ROOT / BULK_LIB)]),
        "grid": run_offline([node, "-e", NODE_PROBE, str(ROOT / GRID)]),
    }


def evaluate(key: object, sources: object, result: object) -> str:
    if (type(key) is not str or key not in CLAUSES
            or type(sources) is not dict or type(result) is not dict
            or set(sources) != set(FILES)
            or any(type(sources[p]) is not str or not sources[p] for p in FILES)):
        return UNKNOWN
    try:
        matrix_rows(sources[MATRIX])
    except ValueError:
        return UNKNOWN
    if (("## 39." not in sources[SPEC]
            or "- " + CLAUSES[key] not in sources[SPEC])):
        return UNKNOWN
    bulk = result.get("bulk")
    grid = result.get("grid")
    if type(bulk) is not dict or type(grid) is not dict:
        return UNKNOWN
    valid, invalid = bulk.get("valid"), bulk.get("invalid")
    captured, sorted_views, limited = (
        grid.get("captured"), grid.get("sorted"), grid.get("limited"))
    if (type(valid) is not dict or type(invalid) is not dict
            or type(captured) is not dict or type(sorted_views) is not dict
            or type(limited) is not dict or grid.get("unknown") is not False):
        return UNKNOWN
    if key in ("S39B-R011", "S39B-R012"):
        resources = ("events","artists","sets","pages","releases","blog")
        if (set(valid) != set(resources)
                or any(type(valid[p]) is not dict
                       or valid[p].get("action") != "set_status"
                       or valid[p].get("resource") != p
                       or valid[p].get("ids") != [7, 8] for p in resources)
                or invalid != {
                    "delete":"INVALID_BULK_ACTION",
                    "bulk_delete":"INVALID_BULK_ACTION",
                    "media":"INVALID_BULK_RESOURCE",
                    "bad_status":"INVALID_BULK_STATUS",
                    "bad_ids":"INVALID_BULK_ID",
                    "too_many":"BULK_LIMIT_EXCEEDED",
                }
                or "BRVTALBulkActions?.open?.(state.module,[...selected])" not in sources[GRID]
                or "const MUTATION_ENDPOINT = '/api/bulk-actions.php';" not in sources[BULK]
                or "method:'POST'" not in sources[BULK]
                or "brvtal_bulk_normalize_request(input_json())" not in sources[BULK_API]
                or "brvtal_bulk_apply($pdo, $request" not in sources[BULK_API]
                or "$pdo->beginTransaction();" not in sources[BULK_LIB]
                or "$pdo->rollBack();" not in sources[BULK_LIB]):
            return UNKNOWN
        if key == "S39B-R011":
            return LOCAL
        return LOCAL if ("NO BULK DELETE" in sources[BULK]
                         and "DELETE FROM" not in sources[BULK_LIB]) else UNKNOWN
    for name in ("artists", "sets", "releases", "blog", "media"):
        row = captured.get(name)
        if (type(row) is not dict or row.get("yes") is not True
                or row.get("table") is not True or row.get("module") is not True
                or row.get("rows") != 2):
            return UNKNOWN
    if key == "S39B-R013":
        expected = ("artists", "sets", "releases", "blog")
        return LOCAL if (
            all(captured[m].get("enabled") == "1" for m in expected)
            and all(sorted_views.get(m) == "0" and limited.get(m) == "0"
                    for m in expected)
            and "const supported = new Set(['artists','sets','releases','blog']);" in sources[ORDER]
            and "container.dataset.orderEnabled === '0'" in sources[ORDER]
            and "state.options.orderingEnabled !== false" in sources[GRID]
        ) else UNKNOWN
    if key == "S39B-R014":
        return LOCAL if (
            all("data-grid-module=\"" + m + "\"" in sources[GRID]
                or captured[m]["module"] for m in ("releases","blog"))
            and "releases: {" in sources[GRID]
            and "blog: {" in sources[GRID]
        ) else UNKNOWN
    # The media primary route is the canonical grid, but the committed JS
    # still retains an alternate card fallback when render() returns false.
    # Do not pretend that the explicit no-separate-model clause is proven.
    if key == "S39B-R015":
        if ("window.BRVTALDataGrid?.render?.('media',grid,items," in sources[MEDIA]
                and "#media-inspector" in sources[MEDIA]
                and "#media-upload" in sources[MEDIA]
                and "grid.innerHTML = [...groups.entries()]" in sources[MEDIA]):
            return GAP
    return UNKNOWN


def operational(_key: object, _claim: object = None) -> str:
    return UNKNOWN


class BrechasBrvtal60Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.content, cls.observed = read_sources(), observe()

    def test_contrato_principal(self) -> None:
        self.assertEqual(set(matrix_rows(self.content[MATRIX])), set(IDS))
        for key in IDS:
            with self.subTest(key=key):
                expected = GAP if key == "S39B-R015" else LOCAL
                self.assertEqual(expected, evaluate(key, self.content, self.observed))
                self.assertEqual(UNKNOWN, operational(key, self.observed))

    def test_falla_cerrado(self) -> None:
        matrix = self.content[MATRIX]
        for key in IDS:
            line = next(s for s in matrix.splitlines()
                        if s.startswith("| " + key + " · "))
            for corrupt in (matrix.replace(line, "", 1), matrix + "\n" + line,
                            matrix.replace(line, line.replace(
                                "NO_VERIFICABLE", "CUBIERTO", 1), 1)):
                with self.subTest(key=key), self.assertRaisesRegex(ValueError, UNKNOWN):
                    matrix_rows(corrupt)
                self.assertEqual(UNKNOWN, evaluate(
                    key, {**self.content, MATRIX: corrupt}, self.observed))
            self.assertEqual(UNKNOWN, evaluate(
                key, {**self.content, SPEC: ""}, self.observed))
            self.assertEqual(UNKNOWN, evaluate(
                key, self.content, {"bulk": {}, "grid": {}}))
        self.assertEqual(UNKNOWN, evaluate("S39B-R999", self.content, self.observed))
        bad = json.loads(json.dumps(self.observed))
        bad["bulk"]["invalid"]["delete"] = "ACCEPTED"
        self.assertEqual(UNKNOWN, evaluate("S39B-R012", self.content, bad))
        bad = json.loads(json.dumps(self.observed))
        bad["grid"]["limited"]["artists"] = "1"
        self.assertEqual(UNKNOWN, evaluate("S39B-R013", self.content, bad))
        bad = json.loads(json.dumps(self.observed))
        bad["grid"]["captured"]["blog"]["table"] = False
        self.assertEqual(UNKNOWN, evaluate("S39B-R014", self.content, bad))

    def test_sin_datos_reales(self) -> None:
        raw = Path(__file__).read_text(encoding="utf-8")
        syntax = ast.parse(raw)
        imported = set()
        for node in ast.walk(syntax):
            if isinstance(node, ast.Import):
                imported.update(a.name.split(".")[0] for a in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                imported.add(node.module.split(".")[0])
        self.assertFalse(imported & {"requests","httpx","urllib","socket",
                                     "playwright","selenium","pymysql","mysql"})
        self.assertNotIn("BEGIN " + "PRIVATE KEY", raw)
        self.assertEqual(set(FILES), set(self.content))
        env = isolated_env("/nonexistent")
        for secret in ("DATABASE_URL","DB_PASSWORD","MYSQL_PWD",
                       "GITHUB_TOKEN","GH_TOKEN","HTTPS_PROXY"):
            self.assertNotIn(secret, env)
        self.assertNotIn("new PDO(", PHP_PROBE)
        self.assertNotIn("fetch(", NODE_PROBE)

    def test_estados_unknown_explicitos(self) -> None:
        for key in IDS:
            self.assertEqual("NO_VERIFICABLE", matrix_rows(self.content[MATRIX])[key][2])
            self.assertEqual(UNKNOWN, operational(key, {"CI":"success","live":True}))
            self.assertIn(evaluate(key, self.content, self.observed), (LOCAL, GAP))
        self.assertEqual(GAP, evaluate("S39B-R015", self.content, self.observed))
        self.assertEqual(UNKNOWN, evaluate(None, self.content, self.observed))
        self.assertEqual(UNKNOWN, operational("S39B-R011", {"approval":True}))


if __name__ == "__main__":
    unittest.main()
