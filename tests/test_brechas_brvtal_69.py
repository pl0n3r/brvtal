#!/usr/bin/env python3
"""#1159: S43-R004 and S44 UX, five offline fail-closed coverage witnesses.

The real migration guards, real DISCADMIN select helper and real Media Library
picker run with synthetic inputs only. Timing, click efficiency, production
schema, manual SQL approval, real browser UX and deployment remain UNKNOWN.
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
LOCAL = "LOCAL_SYNTHETIC_ONLY"
GAP = "BRECHA_DOCUMENTADA"
CLAUSES = {
    "S43-R004": (679, "If new source requires a new production schema, validate the migration first, provide exact SQL when manual production application is required, wait for confirmation, and only then mark the feature fully deployed."),
    "S44-L686": (686, "few clicks;"),
    "S44-L691": (691, "drafts;"),
    "S44-R001": (685, "fast routine administration;"),
    "S44-R003": (688, "visual media selection;"),
}
FILES = {
    "matrix": "docs/matriz-cobertura.md",
    "spec": "docs/BRVTAL-SPEC.md",
    "migrations": "config/migrations.php",
    "registry": "database/migration_schema_migrations_01.sql",
    "migration_test": "tests/migrations-contract.php",
    "core": "discadmin/index-core.php",
    "media": "discadmin/media-library.js",
    "e2e": "tests/e2e/discadmin-admin-shell.spec.mjs",
}
MIGRATION_PROBE = r"""
require $argv[1];
function verdict(callable $fn): string {
    try { $fn(); return 'OK'; } catch (Throwable $e) { return $e->getMessage(); }
}
$plan = [
  'registry_exists'=>true, 'orphaned_records'=>[],
  'migrations'=>[['migration'=>'migration_synthetic_01.sql','state'=>'pending']],
];
$missingRegistry = $plan;
$missingRegistry['registry_exists'] = false;
$checksum = $plan;
$checksum['migrations'][0]['state'] = 'checksum_mismatch';
echo json_encode([
  'additive'=>verdict(fn()=>brvtalMigrationAssertAdditiveSql(
       'CREATE TABLE IF NOT EXISTS synthetic_probe (id INT);')),
  'destructive'=>verdict(fn()=>brvtalMigrationAssertAdditiveSql(
       'DROP TABLE synthetic_probe;')),
  'approved_plan_shape'=>verdict(fn()=>brvtalMigrationVerifyPlanStatus(
       $plan,'migration_synthetic_01.sql')),
  'unexpected_pending'=>verdict(fn()=>brvtalMigrationVerifyPlanStatus(
       $plan,'__NONE__')),
  'missing_registry'=>verdict(fn()=>brvtalMigrationVerifyPlanStatus(
       $missingRegistry,'__NONE__')),
  'checksum_mismatch'=>verdict(fn()=>brvtalMigrationVerifyPlanStatus(
       $checksum,'__NONE__')),
], JSON_THROW_ON_ERROR);
"""
MIGRATION_EXPECTED = {
    "additive": "OK",
    "destructive": "MIGRATION_NON_ADDITIVE_SQL",
    "approved_plan_shape": "OK",
    "unexpected_pending": "MIGRATION_PLAN_DB_DRIFT",
    "missing_registry": "MIGRATION_REGISTRY_MISSING",
    "checksum_mismatch": "MIGRATION_CHECKSUM_MISMATCH",
}

# This VM has no network primitives. The fake fetch returns disposable fixtures.
# It exercises actual checked-in media-library.js picker and select() helper.
NODE_PROBE = r"""
'use strict';
const fs = require('node:fs'), vm = require('node:vm');
const source = JSON.parse(fs.readFileSync(0, 'utf8'));
const requests = [], dispatched = [], notices = [];
const fixtures = [
  {id:7,type:'image',title:'First <unsafe>',file_path:'uploads/first.jpg',
   alt_text:'First alt',file_size:100,created_at:'2026-01-01'},
  {id:9,type:'image',title:'Second',file_path:'uploads/second.jpg',
   alt_text:'Second alt',file_size:100,created_at:'2026-01-01'},
  {id:8,type:'video',title:'Movie',file_path:'uploads/movie.mp4',
   file_size:100,created_at:'2026-01-01'},
];
const grid = {
  innerHTML:'', buttons:[],
  querySelectorAll(selector) {
    if (selector !== '[data-media-id]') return [];
    this.buttons = [...this.innerHTML.matchAll(/data-media-id="(\d+)"/g)]
      .map(m=>({dataset:{mediaId:m[1]},onclick:null}));
    return this.buttons;
  },
};
const search = {oninput:null}, closer = {onclick:null};
const overlay = {
  removed:false, innerHTML:'', className:'',
  querySelector(sel) {
    return ({'.brvtal-media-picker-grid':grid,'[data-search]':search,
             '[data-close]':closer})[sel] || null;
  },
  addEventListener() {}, remove() {this.removed=true;},
};
const document = {
  body:{appendChild() {}},
  documentElement:{},
  getElementById:()=>null,
  querySelectorAll:()=>[],
  createElement(tag) {
    if(tag==='div' && !document.usedOverlay) {
      document.usedOverlay=true;
      return overlay;
    }
    return {
      id:'',className:'',innerHTML:'',setAttribute(){},
      querySelector:()=>({addEventListener(){}}),
      appendChild(){},remove(){},
    };
  },
};
const window = {setTimeout:()=>0};
class MutationObserver {observe() {}}
class Event {constructor(type){this.type=type;}}
const ctx = {
  window,document,MutationObserver,Event,
  fetch:async (url)=>{
    requests.push(url);
    if (url !== '/api/media-library.php?action=list') throw Error('Unexpected URL');
    return {ok:true,json:async()=>({data:fixtures})};
  },
};
vm.runInNewContext(source.media,ctx,{timeout:2500});
if(!window.BRVTALMediaLibrary?.openPicker) process.exit(4);
const esc = value => String(value ?? '').replace(/[&<>"']/g,ch=>
  ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
const draft = vm.runInNewContext(source.select +
  "\nselect('status','Status','draft', [['draft','Draft'],['published','Published']]);",
  {esc},{timeout:2500});
const published = vm.runInNewContext(source.select +
  "\nselect('status','Status','published', [['draft','Draft'],['published','Published']]);",
  {esc},{timeout:2500});
const input = {
  value:'',parentElement:null,closest:()=>null,
  dispatchEvent(event){dispatched.push(event.type);},
};
(async()=>{
  await window.BRVTALMediaLibrary.openPicker(input,{imagesOnly:true});
  const initial=grid.innerHTML;
  const initialIds=grid.buttons.map(b=>Number(b.dataset.mediaId));
  search.oninput({target:{value:'Second'}});
  const filterIds=grid.buttons.map(b=>Number(b.dataset.mediaId));
  const filtered=grid.innerHTML;
  grid.buttons[0]?.onclick?.();
  console.log(JSON.stringify({
    request:requests.length===1 && requests[0]==='/api/media-library.php?action=list',
    imageOnly:initialIds.join(',')==='7,9' && !initial.includes('data-media-id="8"'),
    visual:initial.includes('class="media-preview"') &&
       initial.includes('<img ') && initial.includes('alt="First alt"') &&
       initial.includes('First &lt;unsafe&gt;') && !initial.includes('First <unsafe>'),
    filtered:filterIds.join(',')==='9' && !filtered.includes('data-media-id="7"'),
    selected:input.value==='/uploads/second.jpg' && overlay.removed &&
      dispatched.join(',')==='input,change',
    draft:draft.includes('value="draft" selected') &&
      !draft.includes('value="published" selected'),
    published:published.includes('value="published" selected') &&
      !published.includes('value="draft" selected'),
  }));
})().catch(()=>process.exit(5));
"""
UI_EXPECTED = dict.fromkeys(("request","imageOnly","visual","filtered","selected",
                              "draft","published"),True)
GAPS = {
    "S43-R004": "production migration must be applied/confirmed independently; no live SQL or approval",
    "S44-L686": "few clicks has no measured real-user task journey or click budget",
    "S44-R001": "fast routine administration has no real-user performance/latency baseline",
}


def sources() -> dict[str, str]:
    return {key:(ROOT/path).read_text(encoding="utf-8")
            for key,path in FILES.items()}


def rows(raw: object) -> dict[str, tuple[str,...]]:
    if type(raw) is not str or not raw.strip():
        raise ValueError(UNKNOWN+": missing matrix")
    found = {}
    for line in raw.splitlines():
        match = re.match(r"^\| (S43-R004|S44-L686|S44-L691|S44-R001|S44-R003) · ",line)
        if not match:
            continue
        key = match.group(1)
        cols = tuple(cell.strip() for cell in line.strip("|").split("|"))
        anchor,phrase = CLAUSES[key]
        if (key in found or len(cols)!=5 or cols[1]!=phrase
                or f"[S{key[1:3]} L{anchor}]" not in cols[0]
                or f"BRVTAL-SPEC.md#L{anchor})" not in cols[0]
                or cols[2]!="NO_VERIFICABLE"
                or "Sin aserción contrastada" not in cols[3]
                or "falta enlazar prueba de ese inciso, archivo de código y PR fusionado" not in cols[3]
                or cols[4]!="—"):
            raise ValueError(UNKNOWN+": malformed/duplicate/promoted "+key)
        found[key]=cols
    if set(found)!=set(CLAUSES):
        raise ValueError(UNKNOWN+": missing required rows")
    return found


def spec_clause(raw: object, key: object) -> bool:
    if type(raw) is not str or key not in CLAUSES:
        return False
    heading = "## 43. Migrations\n" if key.startswith("S43") else "## 44. DISCADMIN UX\n"
    if raw.count(heading)!=1:
        return False
    section=raw.split(heading,1)[1].split("\n## ",1)[0]
    return section.count(CLAUSES[key][1])==1


def safe_env(home: str)->dict[str,str]:
    return {"PATH":os.defpath,"HOME":home,"LANG":"C","APP_ENV":"testing"}


def observe(data: dict[str,str])->dict[str,object]:
    """Execute two local sources without inherited env or real connectivity."""
    if not shutil.which("php") or not shutil.which("node"):
        raise AssertionError(UNKNOWN+": offline runtimes unavailable")
    helpers=re.findall(r"^function select\([^\n]+$",data["core"],flags=re.M)
    if len(helpers)!=1:
        raise AssertionError(UNKNOWN+": missing canonical status select helper")
    with tempfile.TemporaryDirectory(prefix="brvtal-1159-") as home:
        env=safe_env(home)
        php=subprocess.run(
            ["php","-d","allow_url_fopen=0","-r",MIGRATION_PROBE,
             str(ROOT/FILES["migrations"])],
            cwd=ROOT,env=env,capture_output=True,text=True,timeout=20,check=False,
        )
        node=subprocess.run(
            ["node","-e",NODE_PROBE],
            input=json.dumps({"media":data["media"],"select":helpers[0]}),
            cwd=ROOT,env=env,capture_output=True,text=True,timeout=20,check=False,
        )
    if php.returncode or node.returncode or php.stderr.strip() or node.stderr.strip():
        raise AssertionError(UNKNOWN+": synthetic execution failed")
    try:
        p,u=json.loads(php.stdout),json.loads(node.stdout)
    except (ValueError,TypeError) as exc:
        raise AssertionError(UNKNOWN+": malformed probe output") from exc
    if type(p) is not dict or set(p)!=set(MIGRATION_EXPECTED) or type(u) is not dict or set(u)!=set(UI_EXPECTED):
        raise AssertionError(UNKNOWN+": incomplete observations")
    return {"migration":p,"ui":u}


def evidence(key: object, data: object, observed: object)->str:
    """Never turn local synthetic checks or quantitative priorities into live proof."""
    if (key not in CLAUSES or type(data) is not dict or set(data)!=set(FILES)
            or any(type(x) is not str or not x for x in data.values())
            or type(observed) is not dict
            or observed!={"migration":MIGRATION_EXPECTED,"ui":UI_EXPECTED}
            or not spec_clause(data["spec"],key)):
        return UNKNOWN
    try:
        rows(data["matrix"])
    except ValueError:
        return UNKNOWN
    if key=="S43-R004":
        valid = ("function brvtalMigrationVerifyPlanStatus" in data["migrations"]
                 and "function brvtalMigrationAssertAdditiveSql" in data["migrations"]
                 and "checksum_sha256 CHAR(64) NOT NULL" in data["registry"]
                 and "MIGRATION_PLAN_DB_DRIFT" in data["migration_test"])
        return GAP if valid else UNKNOWN
    if key in ("S44-L686","S44-R001"):
        return GAP if "mobile navigation uses the existing sidebar" in data["e2e"] else UNKNOWN
    if key=="S44-L691":
        return LOCAL if ("function select(" in data["core"]
                and "['draft','Draft']" in data["core"]
                and observed["ui"]["draft"] and observed["ui"]["published"]) else UNKNOWN
    return LOCAL if ("function openPicker(" in data["media"]
            and "data-media-id=" in data["media"]
            and "brvtal-media-picker-grid" in data["media"]
            and observed["ui"]["visual"] and observed["ui"]["selected"]
            and observed["ui"]["imageOnly"] and observed["ui"]["filtered"]) else UNKNOWN


def gap(key: object,data: object,observed: object)->str:
    if key in GAPS and evidence(key,data,observed)==GAP:
        return f"{key}: BRECHA_DOCUMENTADA — {GAPS[key]}; operational UNKNOWN"
    return UNKNOWN


def operational(_key: object,_claim: object=None)->str:
    return UNKNOWN


class BrechasBrvtal69Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls)->None:
        cls.src=sources()
        cls.observed=observe(cls.src)

    def test_contrato_principal(self)->None:
        self.assertEqual(set(rows(self.src["matrix"])),set(CLAUSES))
        self.assertEqual(MIGRATION_EXPECTED,self.observed["migration"])
        self.assertEqual(UI_EXPECTED,self.observed["ui"])
        for key in CLAUSES:
            with self.subTest(row=key):
                expected=GAP if key in GAPS else LOCAL
                self.assertEqual(expected,evidence(key,self.src,self.observed))
                self.assertEqual(UNKNOWN,operational(key,self.observed))
                if key in GAPS:
                    self.assertIn(key+": BRECHA_DOCUMENTADA",gap(key,self.src,self.observed))

    def test_falla_cerrado(self)->None:
        matrix=self.src["matrix"]
        for key in CLAUSES:
            line=next(x for x in matrix.splitlines() if x.startswith("| "+key+" · "))
            for invalid in (matrix.replace(line,"",1),matrix+"\n"+line,
                            matrix.replace(line,line.replace("NO_VERIFICABLE","CUBIERTO"),1)):
                with self.subTest(row=key):
                    with self.assertRaisesRegex(ValueError,UNKNOWN):
                        rows(invalid)
                    self.assertEqual(UNKNOWN,evidence(key,{**self.src,"matrix":invalid},self.observed))
            for source in FILES:
                damaged={**self.src,source:""}
                self.assertEqual(UNKNOWN,evidence(key,damaged,self.observed))
        for corrupt in (
            {"migration":{**MIGRATION_EXPECTED,"destructive":"OK"},"ui":UI_EXPECTED},
            {"migration":MIGRATION_EXPECTED,"ui":{**UI_EXPECTED,"selected":False}},
            {"ci":"success"},
            {},
        ):
            self.assertEqual(UNKNOWN,evidence("S44-R003",self.src,corrupt))
        self.assertEqual(UNKNOWN,evidence("S44-R999",self.src,self.observed))
        self.assertEqual(UNKNOWN,gap("S44-L691",self.src,self.observed))

    def test_sin_datos_reales(self)->None:
        tree=ast.parse(Path(__file__).read_text(encoding="utf-8"))
        imports={alias.name.split(".")[0] for node in ast.walk(tree)
                 if isinstance(node,ast.Import) for alias in node.names}
        imports|={node.module.split(".")[0] for node in ast.walk(tree)
                  if isinstance(node,ast.ImportFrom) and node.module}
        self.assertFalse(imports&{"requests","httpx","socket","urllib","playwright","pymysql"})
        with tempfile.TemporaryDirectory(prefix="brvtal-1159-check-") as home:
            env=safe_env(home)
            self.assertEqual("testing",env["APP_ENV"])
            for key in ("DB_PASSWORD","DATABASE_URL","MYSQL_PWD","GITHUB_TOKEN","GH_TOKEN",
                        "HTTP_PROXY","HTTPS_PROXY","APP_KEY","AUTHORIZATION","COOKIE"):
                self.assertNotIn(key,env)
        self.assertNotIn("PDO(",MIGRATION_PROBE)
        self.assertNotIn("require('node:http')",NODE_PROBE)
        self.assertNotIn("require('node:net')",NODE_PROBE)
        self.assertEqual(set(FILES),set(self.src))

    def test_estados_unknown_explicitos(self)->None:
        for key in CLAUSES:
            with self.subTest(row=key):
                self.assertEqual("NO_VERIFICABLE",rows(self.src["matrix"])[key][2])
                for claim in (None,True,{"ci":"success"},{"manual_sql":"applied"},
                              {"production":"healthy"},{"click_count":1}):
                    self.assertEqual(UNKNOWN,operational(key,claim))
                if key in GAPS:
                    self.assertIn("operational UNKNOWN",gap(key,self.src,self.observed))
        self.assertFalse(spec_clause("## 44. DISCADMIN UX\n"*2,"S44-L686"))


if __name__=="__main__":
    unittest.main()
