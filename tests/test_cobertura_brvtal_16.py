#!/usr/bin/env python3
"""#1285 — executable offline contracts for BRVTAL grid, bulk and schema.

Runs production JS/PHP helpers against synthetic DOM/PDO inputs. SQL table
evidence comes from committed DDL, not a production database or coverage matrix.
"""
import ast
import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
GRID_IDS = ("S39B-R011","S39B-R012","S39B-R013","S39B-R014","S39B-R015","S39B-R016")
SCHEMA = {
    "S40-L623": ("admins",), "S40-L624": ("events",),
    "S40-L625": ("artists",), "S40-L627": ("sets_media",),
    "S40-L628": ("media",), "S40-L629": ("pages",),
    "S40-L630": ("settings",), "S40-R002": ("event_artists",),
    "S40-R003": ("analytics_events",),
    "S40-R004": ("admin_recovery_codes",),
    "S40-R005": ("event_ticket_types",),
    "S40-R006": ("artist_collective_history",),
    "S40-R007": ("releases","release_artists"),
    "S40-R008": ("blog_posts","blog_tags","blog_post_tags","blog_post_relations"),
}
IDS = GRID_IDS + tuple(SCHEMA)
DDL = ("database/schema.sql","database/migration_content_core_01.sql",
       "database/migration_totp_foundation.sql","database/migration_releases_01.sql",
       "database/migration_blog_01.sql")

PHP = r"""
require_once 'api/bulk-actions-lib.php';
require_once 'config/schema_catalog.php';
$valid=[];
foreach (['events','artists','sets','pages','releases','blog'] as $resource)
 $valid[$resource]=brvtal_bulk_normalize_request([
  'resource'=>$resource,'action'=>'set_status','status'=>'published','ids'=>[3,'3',8]]);
$bad=[];
foreach ([
 'delete'=>['resource'=>'events','action'=>'delete','status'=>'draft','ids'=>[3]],
 'bulk_delete'=>['resource'=>'blog','action'=>'bulk_delete','status'=>'draft','ids'=>[3]],
 'resource'=>['resource'=>'fake','status'=>'draft','ids'=>[3]],
 'status'=>['resource'=>'artists','status'=>'archived','ids'=>[3]],
 'empty'=>['resource'=>'events','status'=>'draft','ids'=>[]],
 'id'=>['resource'=>'events','status'=>'draft','ids'=>['fake']],
 'limit'=>['resource'=>'events','status'=>'draft','ids'=>range(1,101)]
] as $k=>$input) {
 try { brvtal_bulk_normalize_request($input); $bad[$k]='UNEXPECTED_OK'; }
 catch (InvalidArgumentException $e) { $bad[$k]=$e->getMessage(); }
}
class FakeRows1285 extends PDOStatement {
 public function __construct(private array $rows){}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->rows;}
}
class FakePDO1285 extends PDO {
 public int $queries=0;
 public function __construct(private array $rows){}
 public function query(string $sql,?int $fetchMode=null,mixed ...$args):PDOStatement|false{
  $this->queries++;
  if ($sql!=='SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')
   throw new RuntimeException('UNEXPECTED_SCHEMA_QUERY');
  return new FakeRows1285($this->rows);
 }
}
$names=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$db=new FakePDO1285($names);$found=[];
foreach($names as $name)$found[$name]=brvtalSchemaTableExists($db,$name);
$unknown=brvtalSchemaTableExists($db,'not_a_table');
$empty=new FakePDO1285([]);$missing=brvtalSchemaTableExists($empty,'admins');
echo json_encode(['valid'=>$valid,'bad'=>$bad,'found'=>$found,'unknown'=>$unknown,
 'empty'=>$missing,'queries'=>$db->queries,'empty_queries'=>$empty->queries],JSON_THROW_ON_ERROR);
"""

NODE = r"""
const fs=require('node:fs'),vm=require('node:vm');
const src=fs.readFileSync('discadmin/admin-data-grid.js','utf8');
const css=fs.readFileSync('discadmin/admin-data-grid.css','utf8');
let calls=[];
function host(){
 let html='';
 const h={isConnected:true,handlers:{},
  get innerHTML(){return html;},
  set innerHTML(s){html=s;this.handlers={};},
  querySelector(sel){
   if(sel==='[data-grid-bulk]'&&html.includes('data-grid-bulk'))
    return {addEventListener:(_type,fn)=>{this.handlers.bulk=fn;}};
   return null;
  },
  querySelectorAll(sel){
   const kind=sel==='[data-grid-select]'?'select':
              sel==='[data-grid-sort]'?'sort':null;
   if(!kind)return [];
   return [...html.matchAll(new RegExp('data-grid-'+kind+'="([a-zA-Z0-9_-]+)"','g'))]
    .map(match=>{
     const value=match[1],node={dataset:{},checked:false};
     node.addEventListener=(type,fn)=>{
      h.handlers[kind+':'+value+':'+type]=()=>{
       if(kind==='select')node.checked=true;
       fn();
      };
     };
     if(kind==='select')node.dataset.gridSelect=value;
     else node.dataset.gridSort=value;
     return node;
    });
  }
 };
 return h;
}
const win={
 BRVTALBulkActions:{
  supports:m=>['events','artists','sets','pages','releases','blog'].includes(m),
  open:(module,ids)=>calls.push({module,ids})
 },BRVTALContentOrdering:{refresh:()=>{}}
};
vm.runInNewContext(src,{window:win,document:{querySelectorAll:()=>[]},
 console,Promise,queueMicrotask,encodeURIComponent,CSS:{escape:x=>x},
 fetch:async()=>({ok:true,json:async()=>({ok:true,data:{columns:null}})})},{timeout:3000});
const grid=win.BRVTALDataGrid,rendered={},drag={};
for(const m of ['events','artists','sets','pages','releases','blog','media']){
 const h=host();let ok=grid.render(m,h,[{id:7,title:'Synthetic',
   name:'Synthetic',type:'image',status:'draft'}]);
 rendered[m]={ok,html:h.innerHTML};
}
for(const m of ['artists','sets','releases','blog']){
 const h=host();grid.render(m,h,[{id:7,title:'Synthetic',name:'Synthetic'}]);
 const initial=h.innerHTML.includes('data-order-enabled="1"');
 h.handlers['sort:primary:click']?.();
 const sorted=h.innerHTML.includes('data-order-enabled="0"');
 const filtered=host();grid.render(m,filtered,[{id:7,title:'Synthetic'}],{orderingEnabled:false});
 drag[m]={initial,sorted,filtered:filtered.innerHTML.includes('data-order-enabled="0"')};
}
const h=host();grid.render('events',h,[{id:7,title:'Synthetic'}]);
h.handlers['select:7:change']?.();h.handlers.bulk?.();
console.log(JSON.stringify({rendered,drag,calls,
 invalid:[grid.supports('unknown'),grid.render('unknown',host(),[]),grid.render('media',null,[])],
 style:[css.includes('overflow:auto'),css.includes(':focus-visible'),css.includes('@media(max-width:760px)')]}));
"""


def invoke(program, script, stdin=None):
    binary=shutil.which(program)
    if not binary: raise AssertionError("Missing executable: "+program)
    result=subprocess.run([binary, "-r" if program=="php" else "-e",script],
                          text=True,input=stdin,cwd=ROOT,capture_output=True,timeout=20)
    if result.returncode:
        raise AssertionError(program+" real-code probe failed: "+result.stderr[:950])
    return json.loads(result.stdout)


def ddl_tables():
    names=set()
    for rel in DDL:
        sql=(ROOT/rel).read_text(encoding="utf-8")
        found=re.findall(r"\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[\x60]?([a-z][a-z0-9_]*)[\x60]?\s*\(",sql,re.I)
        if not found:raise AssertionError("Missing actual table DDL in "+rel)
        names.update(found)
    return names


class CoberturaBrvtal16Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.ddl=ddl_tables()
        cls.php=invoke("php",PHP,json.dumps(sorted(cls.ddl)))
        cls.js=invoke("node",NODE)

    def test_contrato_principal(self):
        """AC-01: 20 IDs tested through actual production functions."""
        self.assertEqual(len(IDS),20)
        self.assertEqual(len(set(IDS)),20)
        for resource in ("events","artists","sets","pages","releases","blog"):
            with self.subTest(resource=resource):
                self.assertEqual(self.php["valid"][resource]["ids"],[3,8])
                self.assertEqual(self.php["valid"][resource]["action"],"set_status")
        self.assertEqual(self.js["calls"],[{"module":"events","ids":[7]}])
        for module in ("artists","sets","releases","blog"):
            with self.subTest(requirement="S39B-R013",module=module):
                self.assertEqual(self.js["drag"][module],
                                 {"initial":True,"sorted":True,"filtered":True})
        for module in ("releases","blog"):
            html=self.js["rendered"][module]["html"]
            self.assertIn('role="table"',html,"S39B-R014")
            self.assertIn('data-grid-row-id="7"',html)
        media=self.js["rendered"]["media"]["html"]
        self.assertIn('data-grid-module="media"',media,"S39B-R015")
        self.assertIn('data-grid-action="details"',media)
        for module,item in self.js["rendered"].items():
            with self.subTest(requirement="S39B-R016",module=module):
                self.assertTrue(item["ok"])
                self.assertIn('aria-label="Select all current results"',item["html"])
                self.assertIn('aria-label="Select record 7"',item["html"])
        self.assertEqual(self.js["style"],[True,True,True])
        for key,tables in SCHEMA.items():
            with self.subTest(requirement=key):
                for name in tables:
                    self.assertIn(name,self.ddl)
                    self.assertIs(self.php["found"].get(name),True)
        self.assertEqual(self.php["queries"],1)

    def test_falla_cerrado(self):
        """AC-02: destructive bulk, invalid IDs, empty schemas denied."""
        self.assertEqual(self.php["bad"],{
            "delete":"INVALID_BULK_ACTION",
            "bulk_delete":"INVALID_BULK_ACTION",
            "resource":"INVALID_BULK_RESOURCE",
            "status":"INVALID_BULK_STATUS",
            "empty":"BULK_IDS_REQUIRED",
            "id":"INVALID_BULK_ID",
            "limit":"BULK_LIMIT_EXCEEDED",
        },"S39B-R012 must reject bulk deletion")
        self.assertEqual(self.js["invalid"],[False,False,False])
        self.assertIs(self.php["unknown"],False)
        self.assertIs(self.php["empty"],False)
        self.assertEqual(self.php["empty_queries"],1)

    def test_sin_datos_reales(self):
        """AC-03: synthetic-only probes with no network, secrets or DB."""
        source=Path(__file__).read_text(encoding="utf-8")
        imports=set()
        for node in ast.walk(ast.parse(source)):
            if isinstance(node,ast.Import):
                imports.update(n.name.split(".")[0] for n in node.names)
            elif isinstance(node,ast.ImportFrom) and node.module:
                imports.add(node.module.split(".")[0])
        self.assertFalse(imports & {"requests","httpx","urllib","socket","pymysql","mysql"})
        self.assertNotIn("https://",NODE)
        self.assertNotIn("http://",NODE)
        self.assertTrue(all(not Path(p).is_absolute() and ".." not in Path(p).parts for p in DDL))

    def test_ci_gate_contract(self):
        """AC-04: runnable acceptance; GitHub exact-SHA CI is separately required."""
        self.assertEqual(set(IDS),set(GRID_IDS)|set(SCHEMA))
        self.assertEqual(len(self.js["rendered"]),7)
        self.assertEqual(sorted(self.php["found"]),sorted(self.ddl))


if __name__=="__main__":
    unittest.main()
