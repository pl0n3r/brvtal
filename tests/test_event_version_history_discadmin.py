#!/usr/bin/env python3
"""Contrato de Event Version History dentro del editor canónico de Events."""

from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
CONTENT = ROOT / "discadmin" / "content-core.php"
CORE_JS = ROOT / "discadmin" / "content-core.js"
ACTIVITY_JS = ROOT / "discadmin" / "admin-activity.js"
SPEC = ROOT / "docs" / "BRVTAL-SPEC.md"
VERSION = ROOT / "config" / "version.php"
PACKAGE = ROOT / "package.json"


class EventVersionHistoryDiscadminTests(unittest.TestCase):
    def content(self) -> str:
        return CONTENT.read_text(encoding="utf-8")

    def core_js(self) -> str:
        return CORE_JS.read_text(encoding="utf-8")

    def activity_js(self) -> str:
        return ACTIVITY_JS.read_text(encoding="utf-8")

    def history_function(self) -> str:
        core = self.core_js()
        start = core.index("async function openEventHistory()")
        end = core.index("\nasync function initAuth()", start)
        return core[start:end]

    def run_node(self, source: str, *args: str) -> None:
        result = subprocess.run(
            ["node", "-e", source, *args],
            cwd=ROOT,
            check=False,
            text=True,
            capture_output=True,
            timeout=30,
        )
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)

    def test_persisted_event_exposes_read_only_history_control_without_seventh_wizard_step(self) -> None:
        html = self.content()
        core = self.core_js()

        self.assertIn('id="cc-historyBtn"', html)
        self.assertRegex(
            html,
            r'<button\s+[^>]*id="cc-historyBtn"[^>]*type="button"[^>]*class="btn"[^>]*hidden[^>]*disabled[^>]*onclick="BRVTALContentCore\.openEventHistory\(\)"',
        )
        self.assertEqual(html.count('data-step="'), 6)
        self.assertNotIn('data-step="7"', html)
        self.assertNotIn('data-content="7"', html)
        self.assertIn("function syncEventHistoryControl()", core)
        self.assertIn("button.hidden=eventId<1", core)
        self.assertIn("button.disabled=eventId<1", core)
        self.assertIn("syncEventHistoryControl();$('#eventModal').classList.add('open')", core)
        self.assertIn("currentEvent={...currentEvent,id,...payload};\n    syncEventHistoryControl();", core)
        self.assertIn("openEventHistory", core)

        check = subprocess.run(
            ["node", "--check", str(CORE_JS)],
            cwd=ROOT,
            check=False,
            text=True,
            capture_output=True,
            timeout=30,
        )
        self.assertEqual(check.returncode, 0, check.stderr)

    def test_new_event_never_loads_or_opens_history(self) -> None:
        history = self.history_function()
        guard = "if(eventId<1||!modal?.classList.contains('open'))return false;"
        load = "await brvtalLoadEventHistoryModule()"
        open_call = "await module.openHistory('events',eventId,label)"
        self.assertIn(guard, history)
        self.assertIn(load, history)
        self.assertIn(open_call, history)
        self.assertLess(history.index(guard), history.index(load))
        self.assertLess(history.index(guard), history.index(open_call))
        self.assertIn("button.hidden=eventId<1", self.core_js())
        self.assertIn("button.disabled=eventId<1", self.core_js())

    def test_history_loader_reuses_admin_activity_with_deployment_cache_key_and_retry(self) -> None:
        core = self.core_js()
        activity = self.activity_js()

        self.assertIn("function brvtalEventHistoryScriptSrc()", core)
        self.assertIn("new URL('/discadmin/admin-activity.js',location.origin)", core)
        self.assertIn("source.searchParams.get('v')", core)
        self.assertIn("script.src=brvtalEventHistoryScriptSrc()", core)
        self.assertIn("script.dataset.eventHistoryFailed='1'", core)
        self.assertIn("script.remove()", core)
        self.assertIn("brvtalEventHistoryModulePromise=null", core)
        self.assertIn("window.BRVTALAdminActivity = {mount,openDetail,openHistory,closeDetail}", activity)

        harness = r"""
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync(process.argv[1],'utf8');
const prefix=source.slice(0,source.indexOf('window.BRVTALContentCore ='));
const created=[];
let existing=null;
function scriptNode(){
  const listeners={};
  return {
    dataset:{},
    src:'',
    async:false,
    removed:false,
    listeners,
    addEventListener(name,callback){listeners[name]=callback;},
    remove(){this.removed=true;if(existing===this)existing=null;}
  };
}
const document={
  currentScript:{src:'https://brvtal.test/discadmin/content-core.js?v=deploy123'},
  querySelector(selector){
    return selector==='script[data-event-history-script="1"]' ? existing : null;
  },
  createElement(){
    const script=scriptNode();
    created.push(script);
    existing=script;
    return script;
  },
  head:{appendChild(){}}
};
const context={
  assert,
  created,
  window:{},
  document,
  location:{origin:'https://brvtal.test'},
  URL,
  console,
  process
};
vm.createContext(context);
vm.runInContext(prefix + String.raw`
(async()=>{
  assert.equal(brvtalEventHistoryScriptSrc(),'/discadmin/admin-activity.js?v=deploy123');

  const first=brvtalLoadEventHistoryModule();
  assert.equal(created.length,1);
  assert.equal(created[0].src,'/discadmin/admin-activity.js?v=deploy123');
  created[0].listeners.error();
  await assert.rejects(first,/Event Version History module unavailable/);
  assert.equal(created[0].removed,true);

  const second=brvtalLoadEventHistoryModule();
  assert.equal(created.length,2);
  window.BRVTALAdminActivity={openHistory(){}};
  created[1].listeners.load();
  assert.equal(await second,window.BRVTALAdminActivity);
})().catch(error=>{console.error(error);process.exit(1);});
`,context,{filename:'content-core-history-loader.js'});
"""
        self.run_node(harness, str(CORE_JS))

    def test_history_open_uses_events_resource_and_does_not_enter_save_preview_or_insights_paths(self) -> None:
        history = self.history_function()
        activity = self.activity_js()

        self.assertIn("module.openHistory('events',eventId,label)", history)
        self.assertIn("Number(currentEvent?.id||0)!==eventId||!modal.classList.contains('open')", history)
        self.assertNotIn("saveEvent(", history)
        self.assertNotIn("previewEvent(", history)
        self.assertNotIn("BRVTALEventInsights", history)
        self.assertNotIn("fetch(", history)
        self.assertIn("const ENDPOINT = '/api/admin-activity.php';", activity)
        self.assertIn("history:'1',resource,resource_id:String(resourceId),limit:'50'", activity)
        self.assertNotIn("method:'POST'", activity)
        self.assertNotIn("restore", history.lower())
        self.assertNotIn("revert", history.lower())

    def test_spec_and_release_identity_are_updated_to_0_1_118(self) -> None:
        spec = SPEC.read_text(encoding="utf-8")
        version = VERSION.read_text(encoding="utf-8")
        package = json.loads(PACKAGE.read_text(encoding="utf-8"))

        self.assertIn(
            "read-only Event Version History for persisted Events through the canonical Admin Activity audit trail.",
            spec,
        )
        self.assertIn("Event Version History V1 reuses the existing", spec)
        self.assertIn("does not create a second audit/history system or add a seventh wizard step", spec)
        self.assertIn("BRVTAL_APP_VERSION = '0.1.118'", version)
        self.assertEqual(package["version"], "0.1.118")
        self.assertEqual(
            package["scripts"]["test:event-version-history-discadmin"],
            "python3 -m unittest tests/test_event_version_history_discadmin.py",
        )
        self.assertIn(
            "npm run test:event-version-history-discadmin",
            package["scripts"]["test:integration"],
        )


if __name__ == "__main__":
    unittest.main()
