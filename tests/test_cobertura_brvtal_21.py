#!/usr/bin/env python3
"""#1290: real offline proof of the unsaved-editor authentication boundary.

Only S51_2-R011 is locally executable here. The other seven rows require
browser/operational evidence or are governance rules; no matrix is consulted.
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
ROWS = frozenset((
    "S51_1-R009", "S51_1-R012", "S51_1-R013", "S51_1-R014",
    "S51_2-R007", "S51_2-R010", "S51_2-R011", "S51_2-R014",
))
A = frozenset(("S51_2-R011",))
B = {
    "S51_2-R007": "real browser needed for workspace, history, routes and logout",
    "S51_2-R010": "real browser needed to verify canonical URL/workspace rollback",
    "S51_2-R014": "native beforeunload prompt requires browser/page unload",
}
C = {
    "S51_1-R009": "global serial merge policy, not one code-level behavior",
    "S51_1-R012": "independent CI/review/deploy evidence is governance",
    "S51_1-R013": "deploy-bound PR version title is coordination policy",
    "S51_1-R014": "prohibition of destructive production is an authority boundary",
}

# Real checked-in JavaScript modules; fake DOM and session, no network or data.
NODE_PROBE = r"""
'use strict';
const fs=require('node:fs'), vm=require('node:vm');
function scenario(dirty) {
  const events=[], listeners={}, notices=[];
  let closes=0, renders=0, asks=0;
  class HTMLInputElement {
    constructor(){this.type='text';this.value='Synthetic original';
      this.id='title';this.dataset={};this.tagName='INPUT';}
    closest(){return null;}
    getAttribute(){return null;}
  }
  class Element {
    constructor(){this.dataset={};this.inert=false;this.isConnected=true;
      this.classList={contains:k=>k==='open'};}
    addEventListener(){}
    querySelectorAll(s){return s.includes('input:not')?[field]:[];}
    querySelector(){return null;}
    prepend(item){notices.push(item);}
  }
  class MutationObserver {observe(){}}
  class CustomEvent {
    constructor(type,opts){this.type=type;this.detail=opts.detail;}
  }
  const field=new HTMLInputElement(),root=new Element(),html={dataset:{}};
  const document={
    documentElement:html,readyState:'complete',
    querySelectorAll(s){return s==='#modal,#eventModal'?[root]:[];},
    querySelector(s){return s==='#eventModal.open,#modal.open'?root:null;},
    createElement(){return {dataset:{},attributes:{},textContent:'',
      setAttribute(k,v){this.attributes[k]=v;}};},
    addEventListener(){}
  };
  const window={
    state:{authed:true},csrf:'fake-csrf',
    fetch:async()=>{throw Error('NETWORK_NOT_PERMITTED');},
    addEventListener(type,fn){listeners[type]=fn;},
    dispatchEvent(ev){events.push(ev);},
    confirm(){asks++;return false;}
  };
  const ctx={window,document,Element,HTMLInputElement,
    HTMLSelectElement:class HTMLSelectElement{},MutationObserver,CustomEvent,
    requestAnimationFrame(){},closeModal(){closes++;},render(){renders++;}};
  vm.runInNewContext(fs.readFileSync(process.argv[1],'utf8'),ctx,
    {filename:'admin-unsaved-changes.js',timeout:2500});
  vm.runInNewContext(fs.readFileSync(process.argv[2],'utf8'),ctx,
    {filename:'admin-auth-boundary.js',timeout:2500});
  const guard=window.BRVTALUnsavedChanges;
  const auth=window.BRVTALAdminAuthBoundary;
  if(!guard?.isDirty || !auth?.expireSession ||
     typeof listeners.beforeunload!=='function'){
    throw Error('UNKNOWN: actual modules unavailable');
  }
  const initiallyClean=!guard.isDirty(root);
  if(dirty){field.value='Synthetic unsaved edit';guard.touch(root);}
  const markedDirty=guard.isDirty(root)===dirty;
  let prevented=false;
  listeners.beforeunload({preventDefault(){prevented=true;}});
  const first=auth.expireSession(),second=auth.expireSession();
  const notice=notices[0];
  return {
    initiallyClean,markedDirty,prevented,first,second,
    dirtyAfter:guard.isDirty(root),
    preservedDom:root.isConnected&&closes===0&&renders===0,
    noticeCount:notices.length,
    noticeValid:!!notice&&
      notice.textContent.includes('SESSION ENDED')&&
      notice.textContent.includes('UNSAVED CHANGES ARE PRESERVED')&&
      notice.attributes.role==='status'&&
      notice.attributes['aria-live']==='assertive',
    sessionFlag:html.dataset.brvtalAuthRequired==='unsaved',
    unauthenticated:window.state.authed===false&&window.csrf==='',
    eventValid:events.length===1&&events[0].type==='brvtal:auth-required'&&
      events[0].detail.preservedUnsavedChanges===dirty,
    closedCount:closes,renderCount:renders,confirmationCount:asks
  };
}
console.log(JSON.stringify({dirty:scenario(true),clean:scenario(false),
  db:'disabled',network:'disabled',production:'UNKNOWN'}));
"""

def run_actual_js() -> dict:
    node = shutil.which("node")
    if not node:
        raise AssertionError("UNKNOWN: Node executable unavailable")
    environment = {"PATH": os.defpath, "HOME": "/nonexistent",
                   "LANG": "C", "APP_ENV": "testing"}
    executed = subprocess.run([
        node, "-e", NODE_PROBE,
        str(ROOT / "discadmin/admin-unsaved-changes.js"),
        str(ROOT / "discadmin/admin-auth-boundary.js"),
    ], cwd=ROOT, env=environment, capture_output=True, text=True,
        timeout=20, check=False)
    if executed.returncode or executed.stderr.strip():
        raise AssertionError("UNKNOWN: real JS execution failed: "
                             + (executed.stderr + executed.stdout)[-900:])
    try:
        actual = json.loads(executed.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: malformed VM observation") from exc
    if (not isinstance(actual, dict) or set(actual) !=
            {"dirty", "clean", "db", "network", "production"} or
            (actual["db"], actual["network"], actual["production"]) !=
            ("disabled", "disabled", "UNKNOWN")):
        raise AssertionError("UNKNOWN: incomplete/offline evidence")
    return actual


class CoberturaBrvtal21Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        self.assertEqual(ROWS, A | B.keys() | C.keys())
        self.assertFalse(A & B.keys())
        self.assertFalse(A & C.keys())
        actual = run_actual_js()["dirty"]
        for key in ("initiallyClean", "markedDirty", "prevented", "first",
                    "dirtyAfter", "preservedDom", "noticeValid", "sessionFlag",
                    "unauthenticated", "eventValid"):
            with self.subTest(key=key):
                self.assertIs(actual[key], True)
        self.assertIs(actual["second"], False)
        self.assertEqual(actual["noticeCount"], 1)
        self.assertEqual(actual["closedCount"], 0)
        self.assertEqual(actual["renderCount"], 0)
        self.assertEqual(actual["confirmationCount"], 0)

    def test_falla_cerrado(self) -> None:
        actual = run_actual_js()["clean"]
        for key in ("initiallyClean", "markedDirty", "first",
                    "unauthenticated", "eventValid"):
            with self.subTest(key=key):
                self.assertIs(actual[key], True)
        for key in ("second", "prevented", "dirtyAfter",
                    "preservedDom", "noticeValid", "sessionFlag"):
            with self.subTest(key=key):
                self.assertIs(actual[key], False)
        self.assertEqual(actual["noticeCount"], 0)
        self.assertEqual(actual["closedCount"], 1)
        self.assertEqual(actual["renderCount"], 1)
        self.assertEqual(actual["confirmationCount"], 0)
        self.assertTrue(all(B.values()) and all(C.values()))

    def test_sin_datos_reales(self) -> None:
        source = Path(__file__).read_text(encoding="utf-8")
        imported = set()
        for node in ast.walk(ast.parse(source)):
            if isinstance(node, ast.Import):
                imported.update(alias.name.split(".")[0] for alias in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                imported.add(node.module.split(".")[0])
        self.assertLessEqual(imported, {"__future__", "ast", "json", "os",
                                    "pathlib", "shutil", "subprocess", "unittest"})
        for banned in ("fetch(", "curl_exec(", "docs/matriz-cobertura",
                       "brvtal.com.co", "node:https"):
            self.assertNotIn(banned, NODE_PROBE)
        observed = run_actual_js()
        self.assertEqual(observed["db"], "disabled")
        self.assertEqual(observed["network"], "disabled")
        self.assertEqual(observed["production"], "UNKNOWN")


if __name__ == "__main__":
    unittest.main()
