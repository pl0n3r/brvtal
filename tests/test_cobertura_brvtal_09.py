#!/usr/bin/env python3
"""Offline BRVTAL #1278 behavior regressions; never reads coverage-matrix status."""
from __future__ import annotations

import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = r"""
declare(strict_types=1);
define('BRVTAL_SENTRY_TESTING', true);
$GLOBALS['brvtalBootstrapConfigPath'] = $argv[2];
if (!function_exists('mb_substr')) {
  function mb_substr(string $s,int $i,?int $length=null): string {
    return substr($s,$i,$length);
  }
}
require 'config/admin_activity.php';
$before=brvtal_activity_snapshot('events',[
 'id'=>7,'title'=>'OLD','status'=>'draft',
 'seo_description'=>['text'=>'fixture','password'=>'PRIVATE_FIXTURE'],
 'api_token'=>'PRIVATE_FIXTURE'
]);
$after=brvtal_activity_snapshot('events',[
 'id'=>7,'title'=>'NEW','status'=>'published',
 'seo_description'=>['text'=>'fixture','password'=>'PRIVATE_FIXTURE'],
 'api_token'=>'PRIVATE_FIXTURE'
]);
$ok=$argv[1]==='main'
 ? (!isset($after['api_token']) && !isset($after['seo_description']['password'])
    && brvtal_activity_changed_fields($before,$after)===['status','title']
    && brvtal_activity_label('events',$after)==='NEW'
    && brvtal_activity_snapshot('settings',[
       'setting_key'=>'theme.active','setting_value'=>'PRIVATE_FIXTURE'
       ])===['setting_key'=>'theme.active'])
 : (brvtal_activity_snapshot('events',null)===null
    && brvtal_activity_snapshot('unknown',['secret'=>'X'])===[]
    && !brvtalActivitySettingKeyAuditable('secret.value')
    && brvtal_activity_changed_fields(['id'=>7],['id'=>8])===[]);
echo json_encode(['ok'=>$ok,'db'=>'disabled','production'=>'UNKNOWN']);
"""
NODE = r"""
const assert=require('node:assert/strict');
const fs=require('node:fs'),vm=require('node:vm');
const w={};
vm.runInNewContext(fs.readFileSync(process.argv[1],'utf8'),
                   {window:w,Object,Set,Number,String,Array});
const restore=w.BRVTALEventVersionRestore;
const node=()=>({textContent:''});
const head=node(),ringValue=node(),primary=node(),secondary=node(),detail=node();
const ring={value:'',style:{setProperty:(k,v)=>{ring.value=v}},
 querySelector:s=>s==='strong'?ringValue:null};
const copy={querySelector:s=>({strong:primary,span:secondary,small:detail})[s]};
const panel={dataset:{},title:'',querySelector:s=>({
 '.ssv2-panel-head b':head,'.ssv2-storage-ring':ring,
 '.ssv2-storage-copy':copy})[s]};
const root={querySelector:s=>s==='.ssv2-panel.storage'?panel:null};
const document={documentElement:{},
 getElementById:s=>s==='system-status-v2'?root:null};
const sw={};
class MutationObserver{observe(){}}
vm.runInNewContext(fs.readFileSync(process.argv[2],'utf8'),
 {window:sw,document,MutationObserver,setTimeout:()=>0,clearTimeout:()=>{},
  console,Date,Math,Number});
const storage=sw.BRVTALManagedStorageStatus;
const current={id:17,title:'NOW',description:'Current',status:'published'};
const item={id:55,resource:'events',resource_id:17,action:'update',
 after:{id:17,title:'BEFORE',description:'Past',status:'draft',
        published_at:null,password:'REDACTED'}};
if(process.argv[3]==='main'){
 const plan=restore.buildPlan(item,current);
 assert.equal(plan.ok,true);assert.equal(plan.execution,false);
 assert.deepEqual(Array.from(plan.changes,x=>x.field),['title','description']);
 for(const f of ['status','published_at','password']){
  assert.equal(Object.hasOwn(plan.values,f),false);
 }
 assert.equal(current.title,'NOW');
 assert.equal(storage.applyStorage({ok:true,used_percent:25,used:'10 GB',
  quota:'40 GB',free:'30 GB',managed_files:4,
  host_filesystem:{total_bytes:987654321}}),true);
 assert.equal(panel.dataset.storageScope,'brvtal-managed-data');
 assert.equal(ring.value,'25');
 assert.equal(primary.textContent,'10 GB / 40 GB');
 assert.match(detail.textContent,/4 managed files/);
}else{
 for(const invalid of [
  {...item,resource_id:99},{...item,action:'create'},
  {...item,after:{id:17,title:{untrusted:true}}},
  {...item,after:{id:17,status:'draft'}}
 ]){
  assert.equal(restore.buildPlan(invalid,current).ok,false);
 }
 assert.equal(storage.applyStorage({ok:false}),false);
 assert.equal(storage.applyUnavailable(),true);
 assert.equal(panel.dataset.storageScope,'unavailable');
 assert.match(head.textContent,/UNAVAILABLE/);
 assert.match(detail.textContent,/Host filesystem/);
}
console.log(JSON.stringify({ok:true,database:'disabled',
 network:'disabled',production:'UNKNOWN'}));
"""


def probe(kind: str, mode: str) -> dict:
    if mode not in {"main", "negative"}:
        raise ValueError("Invalid mode")
    if kind == "php":
        executable = shutil.which("php")
        if not executable:
            raise AssertionError("PHP CLI missing")
        with tempfile.TemporaryDirectory() as temp:
            config = Path(temp) / "config.php"
            config.write_text(
                "<?php return ['app'=>['timezone'=>'UTC'],"
                "'security'=>['session_name'=>'BRVTAL_FIXTURE']];",
                encoding="utf-8",
            )
            args = [executable, "-r", PHP, mode, str(config)]
            result = subprocess.run(args, cwd=ROOT, text=True,
                                    capture_output=True, timeout=20)
    elif kind == "node":
        executable = shutil.which("node")
        if not executable:
            raise AssertionError("Node CLI missing")
        args = [executable, "-e", NODE,
                str(ROOT / "discadmin/event-version-restore.js"),
                str(ROOT / "discadmin/system-status-storage.js"), mode]
        result = subprocess.run(args, cwd=ROOT, text=True,
                                capture_output=True, timeout=20)
    else:
        raise ValueError("Invalid runtime")
    if result.returncode or result.stderr.strip():
        raise AssertionError((result.stdout + result.stderr)[-1300:])
    response = json.loads(result.stdout)
    if response.get("ok") is not True or response.get("production") != "UNKNOWN":
        raise AssertionError("Incomplete synthetic evidence")
    return response


class CoberturaBrvtal09Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        self.assertEqual(probe("php", "main")["db"], "disabled")
        node = probe("node", "main")
        self.assertEqual((node["database"], node["network"]),
                         ("disabled", "disabled"))

    def test_falla_cerrado(self) -> None:
        self.assertTrue(probe("php", "negative")["ok"])
        self.assertTrue(probe("node", "negative")["ok"])
        with self.assertRaises(ValueError):
            probe("node", "undefined")

    def test_sin_datos_reales(self) -> None:
        self.assertNotIn("matriz-cobertura", PHP + NODE)
        self.assertNotIn("curl_exec(", PHP)
        self.assertNotIn("fetch(", NODE)
        self.assertEqual(probe("php", "main")["production"], "UNKNOWN")
        self.assertEqual(probe("node", "main")["production"], "UNKNOWN")


if __name__ == "__main__":
    unittest.main()
