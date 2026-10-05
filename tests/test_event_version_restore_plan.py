#!/usr/bin/env python3
"""Contrato puro de Event Version Restore Plan V1."""

from __future__ import annotations

import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / "discadmin" / "event-version-restore.js"


class EventVersionRestorePlanTests(unittest.TestCase):
    def run_node(self, source: str) -> None:
        result = subprocess.run(
            ["node", "-e", source, str(MODULE)],
            cwd=ROOT,
            check=False,
            text=True,
            capture_output=True,
            timeout=30,
        )
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)

    def test_event_history_snapshot_builds_closed_editor_restore_plan(self) -> None:
        harness = r"""
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync(process.argv[1],'utf8');
const window={};
vm.runInNewContext(source,{window,Object,Set,Number,String,Array,console},{filename:'event-version-restore.js'});
const mod=window.BRVTALEventVersionRestore;
const current={id:9,title:'GENESIS NOW',slug:'genesis',description:'Current',status:'published',published_at:'2026-10-01 10:00:00'};
const item={id:44,resource:'events',resource_id:9,action:'update',created_at:'2026-09-30 12:00:00',after:{id:9,title:'GENESIS',slug:'genesis',description:'Historical',status:'draft',published_at:null}};
const plan=mod.buildPlan(item,current);
assert.equal(plan.ok,true);
assert.equal(plan.event_id,9);
assert.equal(plan.activity_id,44);
assert.equal(plan.execution,false);
assert.deepEqual(Array.from(plan.fields),['title','slug','description']);
assert.equal(plan.values.title,'GENESIS');
assert.equal(plan.values.description,'Historical');
assert.deepEqual(Array.from(plan.changes, change=>change.field),['title','description']);
assert.equal(Object.hasOwn(plan.values,'status'),false);
assert.equal(Object.hasOwn(plan.values,'published_at'),false);
"""
        self.run_node(harness)

    def test_lifecycle_relations_unknown_or_sensitive_fields_are_never_restorable(self) -> None:
        harness = r"""
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync(process.argv[1],'utf8');
const window={};
vm.runInNewContext(source,{window,Object,Set,Number,String,Array,console},{filename:'event-version-restore.js'});
const mod=window.BRVTALEventVersionRestore;
const current={id:3,title:'Now',status:'published',published_at:'2026-10-01',lineup:[{artist_id:1}]};
const item={id:10,resource:'events',resource_id:3,action:'update',after:{id:3,title:'Then',status:'draft',published_at:null,cancelled_at:'2026-09-01',finished_at:'2026-09-02',sort_order:99,lineup:[{artist_id:2}],ticket_types:[{id:1}],timetable:[{id:2}],password:'secret',unknown_field:'ignored'}};
const plan=mod.buildPlan(item,current);
assert.equal(plan.ok,false);
assert.equal(plan.code,'INCOMPATIBLE_SNAPSHOT');
assert.equal(current.title,'Now');
assert.equal(current.status,'published');

const scalarOnly={...item,after:{id:3,title:'Then',status:'draft',published_at:null,cancelled_at:'x',finished_at:'y',sort_order:9,password:'secret',unknown_field:'ignored'}};
const safe=mod.buildPlan(scalarOnly,current);
assert.equal(safe.ok,true);
for(const forbidden of ['status','published_at','cancelled_at','finished_at','sort_order','password','unknown_field']){
  assert.equal(Object.hasOwn(safe.values,forbidden),false);
}
"""
        self.run_node(harness)

    def test_wrong_event_create_delete_or_incompatible_snapshot_fails_closed_without_side_effects(self) -> None:
        harness = r"""
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync(process.argv[1],'utf8');
const window={};
vm.runInNewContext(source,{window,Object,Set,Number,String,Array,console},{filename:'event-version-restore.js'});
const mod=window.BRVTALEventVersionRestore;
const current={id:7,title:'Current'};
const original=JSON.stringify(current);
function item(action='update',resourceId=7,after={id:7,title:'Old'}){return {id:5,resource:'events',resource_id:resourceId,action,after};}
for(const candidate of [
  item('update',8,{id:8,title:'Other'}),
  item('create'),
  item('delete'),
  item('update',7,null),
  item('update',7,{id:8,title:'Wrong'}),
  item('update',7,{id:7,title:{nested:true}}),
  item('update',7,{id:7,status:'draft'}),
]){
  const plan=mod.buildPlan(candidate,current);
  assert.equal(plan.ok,false);
  assert.equal(JSON.stringify(current),original);
}
"""
        self.run_node(harness)

    def test_module_is_syntax_valid_and_side_effect_free_by_contract(self) -> None:
        check = subprocess.run(
            ["node", "--check", str(MODULE)],
            cwd=ROOT,
            check=False,
            text=True,
            capture_output=True,
            timeout=30,
        )
        self.assertEqual(check.returncode, 0, check.stderr)
        source = MODULE.read_text(encoding="utf-8")
        for forbidden in ("fetch(", "XMLHttpRequest", "localStorage", "sessionStorage", "document."):
            self.assertNotIn(forbidden, source)
        self.assertIn("execution:false", source)


if __name__ == "__main__":
    unittest.main()
