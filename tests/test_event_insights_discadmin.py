#!/usr/bin/env python3
"""Contrato del panel Event Insights integrado al editor canónico de Events."""

from __future__ import annotations

import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
CONTENT = ROOT / "discadmin" / "content-core.php"
CORE_JS = ROOT / "discadmin" / "content-core.js"
INSIGHTS_JS = ROOT / "discadmin" / "event-insights.js"


class EventInsightsDiscadminTests(unittest.TestCase):
    def content(self) -> str:
        return CONTENT.read_text(encoding="utf-8")

    def core_js(self) -> str:
        return CORE_JS.read_text(encoding="utf-8")

    def insights_js(self) -> str:
        return INSIGHTS_JS.read_text(encoding="utf-8")

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

    def test_event_editor_renders_read_only_bounded_insights_from_canonical_endpoint(self) -> None:
        html = self.content()
        core = self.core_js()
        insights = self.insights_js()

        for path in (CORE_JS, INSIGHTS_JS):
            check = subprocess.run(
                ["node", "--check", str(path)],
                cwd=ROOT,
                check=False,
                text=True,
                capture_output=True,
                timeout=30,
            )
            self.assertEqual(check.returncode, 0, check.stderr)

        self.assertIn('data-event-insights-panel', html)
        self.assertNotIn('data-step="7"', html)
        self.assertNotIn('data-content="7"', html)
        self.assertLess(html.index("</form>"), html.index("data-event-insights-panel"))
        self.assertIn('id="eventInsightsWindow"', html)
        for metric in ("Users", "Sessions", "Views"):
            self.assertIn(f'id="eventInsights{metric}"', html)
            self.assertIn(f'id="eventInsights{metric}Previous"', html)

        self.assertIn("document.currentScript?.src", core)
        self.assertEqual(core.count("function brvtalEventInsightsScriptSrc()"), 1)
        self.assertIn("source.searchParams.get('v')", core)
        self.assertIn("script.src=brvtalEventInsightsScriptSrc()", core)
        self.assertIn("script.remove()", core)
        self.assertIn("script.dataset.eventInsightsFailed='1'", core)
        self.assertIn("brvtalEventInsightsModulePromise=null", core)
        self.assertIn("void refreshEventInsights(currentEvent,insightsRequestId)", core)
        self.assertNotIn("loads.push(refreshEventInsights(", core)

        self.assertIn("'/api/admin-event-analytics.php?id='", insights)
        self.assertIn("method:'GET'", insights)
        self.assertIn("credentials:'same-origin'", insights)
        self.assertIn("cache:'no-store'", insights)
        self.assertNotIn("method:'POST'", insights)

    def test_draft_private_or_unavailable_event_never_queries_or_renders_fake_zero_metrics(self) -> None:
        harness = r"""
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync(process.argv[1],'utf8');

function element(value=''){
  return {dataset:{},textContent:'',value,onchange:null};
}
function ui(){
  const panel=element();
  const nodes={
    '#eventInsightsStatus':element(),
    '#eventInsightsWindow':element('7d'),
    '#eventInsightsUsers':element(),
    '#eventInsightsUsersPrevious':element(),
    '#eventInsightsSessions':element(),
    '#eventInsightsSessionsPrevious':element(),
    '#eventInsightsViews':element(),
    '#eventInsightsViewsPrevious':element(),
  };
  return {
    panel,
    nodes,
    root:{
      querySelector(selector){
        if(selector==='[data-event-insights-panel]')return panel;
        return nodes[selector]||null;
      }
    }
  };
}
function response(data,status=200){
  return {status,ok:status>=200&&status<300,json:async()=>data};
}
function payload(eventId,users){
  return {
    ok:true,
    data:{
      event_id:eventId,
      state:'FRESH',
      metrics:{users,sessions:users+1,views:users+2},
      previous:null
    }
  };
}
function moduleWith(fetchImpl){
  const window={};
  const context={
    window,
    fetch:fetchImpl,
    location:{href:'/discadmin/'},
    AbortController,
    console,
    URL,
  };
  vm.createContext(context);
  vm.runInContext(source,context,{filename:'event-insights.js'});
  return window.BRVTALEventInsights;
}

(async()=>{
  let calls=0;
  let state=ui();
  let mod=moduleWith(async()=>{
    calls+=1;
    return response(payload(1,10));
  });
  mod.mount(state.root);
  const draft=await mod.load({id:1,status:'draft'});
  assert.equal(draft.state,'NOT PUBLISHED');
  assert.equal(calls,0);
  assert.equal(state.nodes['#eventInsightsUsers'].textContent,'—');

  calls=0;
  state=ui();
  mod=moduleWith(async()=>{
    calls+=1;
    return response(payload(999,10));
  });
  mod.mount(state.root);
  const mismatch=await mod.load({id:1,status:'published'});
  assert.equal(calls,1);
  assert.equal(mismatch.state,'UNAVAILABLE');
  assert.equal(state.nodes['#eventInsightsUsers'].textContent,'—');
  assert.equal(state.panel.dataset.state,'unavailable');

  const pending=[];
  state=ui();
  mod=moduleWith(()=>new Promise(resolve=>pending.push(resolve)));
  mod.mount(state.root);
  const first=mod.load({id:1,status:'published'});
  const second=mod.load({id:2,status:'published'});
  assert.equal(pending.length,2);
  pending[1](response(payload(2,22)));
  assert.equal((await second).state,'FRESH');
  assert.equal(state.nodes['#eventInsightsUsers'].textContent,'22');
  pending[0](response(payload(1,11)));
  assert.equal((await first).state,'STALE_REQUEST');
  assert.equal(state.nodes['#eventInsightsUsers'].textContent,'22');
})().catch(error=>{
  console.error(error);
  process.exit(1);
});
"""
        self.run_node(harness, str(INSIGHTS_JS))

        core = self.core_js()
        insights = self.insights_js()
        self.assertIn("const originalCloseEvent=closeEvent", core)
        self.assertIn("const result=originalCloseEvent(force)", core)
        self.assertIn("result===true&&wasOpen&&!modal.classList.contains('open')", core)
        self.assertIn("insightsRequest+=1", core)
        self.assertIn("window.BRVTALEventInsights?.reset?.()", core)
        self.assertIn("textContent='—'", insights)
        self.assertNotRegex(insights, r"textContent\s*=\s*['\"]0['\"]")
        self.assertNotIn("innerHTML", insights)
        self.assertNotIn("csrf", insights.lower())

    def test_loading_fresh_stale_not_configured_and_unavailable_states_are_accessible_and_distinct(self) -> None:
        html = self.content()
        insights = self.insights_js()

        self.assertRegex(
            html,
            r'<output\s+[^>]*id="eventInsightsStatus"[^>]*aria-live="polite"[^>]*aria-label="Event Insights status"',
        )
        for state in (
            "LOADING",
            "FRESH",
            "STALE",
            "NOT CONFIGURED",
            "UNAVAILABLE",
            "NOT PUBLISHED",
        ):
            self.assertIn(state, insights)
        self.assertIn("Cached metrics; source is stale.", insights)
        self.assertIn("Analytics is not configured.", insights)
        self.assertIn("Analytics is temporarily unavailable.", insights)

    def test_panel_preserves_existing_event_workflow_navigation_and_editor_contracts(self) -> None:
        html = self.content()
        core = self.core_js()

        for step in (
            "01 · IDENTITY",
            "02 · DATE & PLACE",
            "03 · LIFECYCLE",
            "04 · TICKETS",
            "05 · ROSTER",
            "06 · TIMETABLE",
        ):
            self.assertIn(step, html)

        for invariant in (
            "eventWizardLastStep()",
            "saveEvent=async function()",
            "previewEvent",
            "eventTimetable",
            "eventArtists",
            "tickets",
            "BRVTALContentCoreLineup",
        ):
            self.assertIn(invariant, core)

        self.assertIn('data-event-insights-panel', html)
        self.assertNotIn('data-step="7"', html)
        self.assertNotIn('data-content="7"', html)
        self.assertIn("eventWizardLastStep()", core)
        self.assertIn("window.BRVTALEventInsights", core)
        self.assertIn("Object.assign(window.BRVTALContentCore", core)
        self.assertNotIn("window.BRVTALContentCore =", self.insights_js())


if __name__ == "__main__":
    unittest.main()
