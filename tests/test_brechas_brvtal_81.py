#!/usr/bin/env python3
"""BRVTAL #1171: three fail-closed offline witnesses for SPEC section 51.2.

Run the actual dirty-editor manager and authentication boundary in an inert
Node VM. Synthetic DOM, fake session, no real credentials, network, browser or
production. In particular URL restoration and native browser prompts remain
UNKNOWN until independent end-to-end evidence exists.
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
CONTRAST = "docs/contraste-spec_editor.md"
EDITOR = "discadmin/admin-unsaved-changes.js"
AUTH = "discadmin/admin-auth-boundary.js"
E2E = "tests/e2e/discadmin-unsaved-changes.spec.mjs"
FILES = (MATRIX, SPEC, CONTRAST, EDITOR, AUTH, E2E)
UNKNOWN = "UNKNOWN"
LOCAL = "CONTRATO_LOCAL_SINTETICO"
GAP = "BRECHA_DOCUMENTADA"
ROWS = {
    "S51_2-R010": (803, "If a later programmatic edit appears, DISCADMIN restores the previous canonical workspace/URL and preserves the dirty editor."),
    "S51_2-R011": (804, "If authentication expires while a dirty editor is pending, the shell preserves that editor DOM and shows an explicit session-ended state instead of rendering it away."),
    "S51_2-R014": (807, "Browser/page unload retains native unsaved-change protection while a tracked dirty editor is open."),
}
MISSING = {
    "S51_2-R010": "real_history_and_canonical_workspace_URL_restoration",
    "S51_2-R011": "real_authenticated_browser_session_expiration",
    "S51_2-R014": "native_browser_beforeunload_confirmation",
}

# Both scripts execute as checked in. All DOM and session information is fake.
# No browser, navigation, outbound fetch, cookies, credentials, or real users.
NODE_PROBE = r"""
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const events = [], listeners = {}, notices = [];
let closes = 0, renders = 0, confirms = 0;
class HTMLInputElement {
  constructor() {
    this.type = 'text'; this.value = 'Original synthetic';
    this.id = 'title'; this.dataset = {}; this.tagName = 'INPUT';
  }
  closest() { return null; }
  getAttribute() { return null; }
}
class Element {
  constructor() {
    this.dataset = {}; this.inert = false; this.isConnected = true;
    this.classList = {contains: key => key === 'open'};
  }
  addEventListener() {}
  querySelectorAll(sel) { return sel.includes('input:not') ? [field] : []; }
  querySelector() { return null; }
  prepend(item) { notices.push(item); }
}
class MutationObserver {observe() {}}
class CustomEvent {
  constructor(type,options) {this.type=type;this.detail=options.detail;}
}
const field = new HTMLInputElement(), root = new Element();
const html = {dataset: {}};
const document = {
  documentElement: html,
  readyState:'complete',
  querySelectorAll(selector) {
    return selector === '#modal,#eventModal' ? [root] : [];
  },
  querySelector(selector) {
    return selector === '#eventModal.open,#modal.open' ? root : null;
  },
  createElement() {
    return {dataset:{},attributes:{},className:'',textContent:'',
      setAttribute(k,v) {this.attributes[k]=v;}};
  },
  addEventListener() {},
};
const window = {
  state:{authed:true}, csrf:'synthetic-token-not-a-secret',
  fetch:async()=>{throw Error('NETWORK_NOT_PERMITTED');},
  addEventListener(type,fn) {listeners[type]=fn;},
  dispatchEvent(event) {events.push(event);},
  confirm() {confirms++;return true;},
};
const ctx = {window,document,Element,HTMLInputElement,
  HTMLSelectElement:class HTMLSelectElement{},MutationObserver,
  CustomEvent,requestAnimationFrame() {},
  closeModal() {closes++;},render() {renders++;},
};
vm.runInNewContext(fs.readFileSync(process.argv[1],'utf8'),ctx,
                   {filename:'admin-unsaved-changes.js',timeout:2500});
vm.runInNewContext(fs.readFileSync(process.argv[2],'utf8'),ctx,
                   {filename:'admin-auth-boundary.js',timeout:2500});
const guard=window.BRVTALUnsavedChanges;
const auth=window.BRVTALAdminAuthBoundary;
if(!guard?.requestNavigation || !auth?.expireSession ||
   typeof listeners.beforeunload!=='function') process.exit(5);
function beforeunload() {
  let prevented=false;
  listeners.beforeunload({preventDefault() {prevented=true;}});
  return prevented;
}
const pristineUnload=beforeunload() === false;
field.value='First synthetic unsaved change';
guard.touch(root);
const dirty=guard.isDirty(root) && root.dataset.unsavedChanges==='dirty';
const dirtyUnload=beforeunload() === true;
const token=guard.requestNavigation('workspace');
const locked=Number.isInteger(token) && token>0 && root.inert===true;
field.value='Second programmatic synthetic edit';
guard.touch(root);
const rejected=guard.commitNavigation(token)===false;
const restored=!root.inert && guard.isDirty(root) &&
  root.dataset.unsavedChanges==='dirty' && root.isConnected;
const expired=auth.expireSession()===true;
const preserved=notices.length===1 &&
  notices[0].textContent.includes('SESSION ENDED') &&
  notices[0].textContent.includes('UNSAVED CHANGES ARE PRESERVED') &&
  notices[0].attributes.role==='status' &&
  notices[0].attributes['aria-live']==='assertive' &&
  html.dataset.brvtalAuthRequired==='unsaved' &&
  root.isConnected && guard.isDirty(root) && closes===0 && renders===0;
const event=events.length===1 && events[0].type==='brvtal:auth-required' &&
  events[0].detail.preservedUnsavedChanges===true;
const authState=window.state.authed===false && window.csrf==='';
const expiredUnload=beforeunload()===true;
guard.markClean(root);
const cleanUnload=beforeunload()===false && !guard.isDirty(root);
guard.end(root);
const closedUnload=beforeunload()===false;
console.log(JSON.stringify({
  pristineUnload,dirty,dirtyUnload,locked,rejected,restored,
  expired,preserved,event,authState,expiredUnload,
  cleanUnload,closedUnload,confirmed:confirms===1
}));
"""
EXPECTED = dict.fromkeys((
    "pristineUnload", "dirty", "dirtyUnload", "locked", "rejected",
    "restored", "expired", "preserved", "event", "authState",
    "expiredUnload", "cleanUnload", "closedUnload", "confirmed",
), True)


def sources() -> dict[str, str]:
    return {name:(ROOT / name).read_text(encoding="utf-8") for name in FILES}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    """Require exact three original rows and never self-certify coverage."""
    if type(raw) is not str or not raw:
        raise ValueError(UNKNOWN + ": missing matrix")
    found = {}
    for line in raw.splitlines():
        match = re.match(r"^\|\s*(S51_2-R01[014])\s*·\s*", line)
        if match is None:
            continue
        key = match.group(1)
        line_no,phrase = ROWS[key]
        parts = tuple(x.strip() for x in line.strip().strip("|").split("|"))
        if (key in found or len(parts)!=5 or
                not parts[0].startswith(key + " · ") or
                f"[S51_2 L{line_no}]" not in parts[0] or
                f"BRVTAL-SPEC.md#L{line_no}" not in parts[0] or
                parts[1]!=phrase or parts[2]!="NO_VERIFICABLE" or
                "Sin aserción integral confirmada" not in parts[3] or
                "[contraste](contraste-spec_editor.md)" not in parts[3] or
                parts[4]!="—"):
            raise ValueError(UNKNOWN + ": duplicate, altered or promoted " + key)
        found[key] = parts
    if set(found)!=set(ROWS):
        raise ValueError(UNKNOWN + ": missing requested rows")
    return found


def contrast_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str or not raw:
        raise ValueError(UNKNOWN + ": missing contrast")
    found = {}
    for line in raw.splitlines():
        match = re.match(r"^\|\s*(S51_2-R01[014])\s*\|",line)
        if match is None:
            continue
        key=match.group(1)
        parts=tuple(x.strip() for x in line.strip().strip("|").split("|"))
        if (key in found or len(parts)!=6 or parts[0]!=key or
                parts[1]!="NO_VERIFICABLE" or
                parts[2:4]!=("—","—") or
                "BRVTAL-SPEC.md" not in parts[4] or
                "no hay aserción completa" not in parts[5].lower()):
            raise ValueError(UNKNOWN + ": invalid contrast " + key)
        found[key]=parts
    if set(found)!=set(ROWS):
        raise ValueError(UNKNOWN + ": missing contrast rows")
    return found


def source_contract(files: object, key: object) -> bool:
    if (type(key) is not str or key not in ROWS or
            type(files) is not dict or set(files)!=set(FILES) or
            any(type(files[name]) is not str or not files[name] for name in FILES)):
        return False
    try:
        matrix_rows(files[MATRIX])
        contrast_rows(files[CONTRAST])
    except ValueError:
        return False
    heading="## 51.2 Unsaved editor change protection\n"
    if files[SPEC].count(heading)!=1:
        return False
    block=files[SPEC].split(heading,1)[1].split("\n## ",1)[0]
    return block.count(ROWS[key][1])==1


def isolated_env() -> dict[str, str]:
    return {"PATH":os.defpath,"HOME":"/nonexistent",
            "LANG":"C","APP_ENV":"testing"}


def probe() -> dict[str, bool]:
    executable=shutil.which("node")
    if not executable:
        raise AssertionError(UNKNOWN + ": Node unavailable")
    result=subprocess.run(
        [executable,"-e",NODE_PROBE,str(ROOT / EDITOR),str(ROOT / AUTH)],
        cwd=ROOT,env=isolated_env(),text=True,capture_output=True,
        timeout=20,check=False,
    )
    if result.returncode!=0 or result.stderr.strip():
        raise AssertionError(UNKNOWN + ": synthetic VM execution failed")
    try:
        output=json.loads(result.stdout)
    except (ValueError, TypeError) as exc:
        raise AssertionError(UNKNOWN + ": malformed VM observation") from exc
    if type(output) is not dict or set(output)!=set(EXPECTED) or any(
        type(v) is not bool for v in output.values()
    ):
        raise AssertionError(UNKNOWN + ": incomplete/ambiguous VM evidence")
    return output


def evidence(key: object, files: object, observed: object) -> dict[str, str]:
    verdict={"local":UNKNOWN,"full_scope":UNKNOWN,"ci":UNKNOWN,
             "browser":UNKNOWN,"production":UNKNOWN}
    if not source_contract(files,key) or type(observed) is not dict or observed!=EXPECTED:
        return verdict
    if key=="S51_2-R010":
        needed=("locked","rejected","restored","dirty")
        source_ok=("function commitNavigation(token)" in files[EDITOR]
                   and "function cancelNavigation(token)" in files[EDITOR]
                   and "navigation commit refuses a root changed after confirmation" in files[E2E])
        # Manager rollback is observed, but actual canonical URL/workspace restore
        # is not proved in this isolated VM. Never promote the combined claim.
        verdict["local"]=GAP if source_ok and all(observed[k] for k in needed) else UNKNOWN
    elif key=="S51_2-R011":
        needed=("expired","preserved","event","authState","dirty")
        source_ok=("function preserveUnsavedAuthState()" in files[AUTH]
                   and "function expireSession()" in files[AUTH])
        verdict["local"]=LOCAL if source_ok and all(observed[k] for k in needed) else UNKNOWN
    elif key=="S51_2-R014":
        needed=("pristineUnload","dirtyUnload","expiredUnload",
                "cleanUnload","closedUnload")
        source_ok=("window.addEventListener('beforeunload'" in files[EDITOR])
        verdict["local"]=LOCAL if source_ok and all(observed[k] for k in needed) else UNKNOWN
    return verdict


def missing_evidence(key: object, files: object, observed: object) -> dict[str, str]:
    if type(key) is not str or key not in ROWS:
        return {"status":UNKNOWN,"missing":"unrecognized"}
    if evidence(key,files,observed)["local"]==UNKNOWN:
        return {"status":UNKNOWN,"missing":"source_or_probe_invalid"}
    return {"status":UNKNOWN,"missing":MISSING[key]}


def operational(_key: object, _claim: object = None) -> str:
    """A local script can never attest real UI, CI, or production deployment."""
    return UNKNOWN


class BrechasBrvtal81Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.files=sources()
        cls.observed=probe()

    def test_contrato_principal(self) -> None:
        self.assertEqual(set(ROWS),set(matrix_rows(self.files[MATRIX])))
        self.assertEqual(set(ROWS),set(contrast_rows(self.files[CONTRAST])))
        self.assertEqual(EXPECTED,self.observed)
        for key in ROWS:
            with self.subTest(id=key):
                self.assertTrue(source_contract(self.files,key))
                expected=GAP if key=="S51_2-R010" else LOCAL
                verdict=evidence(key,self.files,self.observed)
                self.assertEqual(expected,verdict["local"])
                self.assertEqual(UNKNOWN,verdict["full_scope"])
                missing=missing_evidence(key,self.files,self.observed)
                self.assertEqual(UNKNOWN,missing["status"])
                self.assertEqual(MISSING[key],missing["missing"])

    def test_falla_cerrado(self) -> None:
        raw=self.files[MATRIX]
        for key in ROWS:
            line=next(s for s in raw.splitlines()
                      if s.startswith("| " + key + " · "))
            for damaged in (
                raw.replace(line,"",1),
                raw + "\n" + line,
                raw.replace(line,line.replace("NO_VERIFICABLE","CUBIERTO"),1),
                raw.replace(line,line.replace(ROWS[key][1],"Ambiguous"),1),
            ):
                with self.subTest(row=key):
                    with self.assertRaisesRegex(ValueError,UNKNOWN):
                        matrix_rows(damaged)
                    self.assertEqual(UNKNOWN,evidence(
                        key,{**self.files,MATRIX:damaged},self.observed)["local"])
            for name in FILES:
                self.assertEqual(UNKNOWN,evidence(
                    key,{**self.files,name:""},self.observed)["local"])
            self.assertEqual(UNKNOWN,evidence(
                key,self.files,{**EXPECTED,"preserved":False})["local"])
            self.assertEqual(UNKNOWN,evidence(key,self.files,{})["local"])
        self.assertEqual(UNKNOWN,evidence("S51_2-R999",self.files,self.observed)["local"])
        self.assertEqual(UNKNOWN,evidence(None,self.files,self.observed)["local"])
        bad_contrast=self.files[CONTRAST].replace(
            "| S51_2-R010 | NO_VERIFICABLE |",
            "| S51_2-R010 | VERIFICADO |",1)
        with self.assertRaisesRegex(ValueError,UNKNOWN):
            contrast_rows(bad_contrast)
        self.assertFalse(source_contract(
            {**self.files,CONTRAST:bad_contrast},"S51_2-R010"))

    def test_sin_datos_reales(self) -> None:
        tree=ast.parse(Path(__file__).read_text(encoding="utf-8"))
        imported={alias.name.split(".")[0] for node in ast.walk(tree)
                  if isinstance(node,ast.Import) for alias in node.names}
        imported|={node.module.split(".")[0] for node in ast.walk(tree)
                   if isinstance(node,ast.ImportFrom) and node.module}
        self.assertFalse(imported & {"requests","httpx","socket","urllib",
                                     "playwright","selenium","pymysql"})
        for key in ("DB_PASSWORD","DATABASE_URL","MYSQL_PWD",
                    "GITHUB_TOKEN","GH_TOKEN","HTTP_PROXY","HTTPS_PROXY","COOKIE"):
            self.assertNotIn(key,isolated_env())
        self.assertIn("NETWORK_NOT_PERMITTED",NODE_PROBE)
        self.assertNotIn("require('node:http')",NODE_PROBE)
        self.assertNotIn("require('node:net')",NODE_PROBE)
        self.assertEqual(set(FILES),set(self.files))

    def test_estados_unknown_explicitos(self) -> None:
        for key in ROWS:
            with self.subTest(row=key):
                verdict=evidence(key,self.files,self.observed)
                self.assertEqual("NO_VERIFICABLE",matrix_rows(self.files[MATRIX])[key][2])
                for gate in ("full_scope","ci","browser","production"):
                    self.assertEqual(UNKNOWN,verdict[gate])
                for fake in (None,True,{"ci":"success"},{"browser":"confirmed"},
                             {"session":"real"},{"url_restored":True}):
                    self.assertEqual(UNKNOWN,operational(key,fake))
        self.assertEqual(UNKNOWN,missing_evidence(
            "S51_2-R010",self.files,self.observed)["status"])


if __name__=="__main__":
    unittest.main()
