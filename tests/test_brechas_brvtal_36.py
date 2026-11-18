#!/usr/bin/env python3
"""BRVTAL #1126 · §28 R010-R014: isolated browser contracts, never live evidence.

This single offline file observes the real System Status render helpers with
synthetic fixtures; missing/ambiguous fields remain UNKNOWN, not deployment
approval. The coverage matrix is intentionally left unchanged.
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
SPEC = ROOT / "docs/BRVTAL-SPEC.md"
STATUS = ROOT / "discadmin/system-status-v2.js"
STORAGE = ROOT / "discadmin/system-status-storage.js"
QUOTA = ROOT / "discadmin/storage-metrics.php"
TECHNICAL = ROOT / "discadmin/technical.php"
IDS = ("S28-R010", "S28-R011", "S28-R012", "S28-R013", "S28-R014")
SUMMARY = {
    "S28-R010": "Content Health summary;",
    "S28-R011": "recent admin activity;",
    "S28-R012": "actionable issues;",
    "S28-R013": "read-only Advanced Diagnostics.",
    "S28-R014": "The visible storage capacity must represent BRVTAL-managed application data",
}
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"

# Actual committed JS is evaluated in a VM without timers, browser, network,
# access to real sessions or any writes. The only injected instruction exports
# references to existing, unmodified functions for observation.
PROBE = r"""
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('discadmin/system-status-v2.js', 'utf8');
const storage = fs.readFileSync('discadmin/system-status-storage.js', 'utf8');
const anchor = '  const observer = new MutationObserver(';
if (source.split(anchor).length !== 2) throw Error('UNKNOWN: cannot isolate status');
const exposed = source.replace(anchor,
  '  globalThis.__probe = {contentHealthBlock,activityList,platformIssueList,' +
  'supplementalIssues,githubBacklog,renderLogPayload};\n' + anchor);
let network = 0;
const env = {
  Intl, URLSearchParams, window: {},
  document: {documentElement:{},getElementById(){return null;}},
  MutationObserver: class {observe(){}},
  setTimeout(){return 0;}, clearTimeout(){},
  fetch(){network++; throw Error('OFFLINE_NETWORK_FORBIDDEN');}
};
vm.runInNewContext(exposed, env, {timeout:1500});
vm.runInNewContext(storage, env, {timeout:1500});
const p = env.__probe;
const goodHealth = p.contentHealthBlock({ok:true,data:{
  score:78,ready:3,needs_attention:2,missing_visuals:1,seo_gaps:4}});
const goodActivity = p.activityList({ok:true,data:{items:[{
  action:'update_event',resource_label:'Fixture record',
  admin_name:'Synthetic admin',created_at:'invalid'}],total:1}});
const issues = p.platformIssueList([{severity:'warning',title:'FIXTURE ALERT',
  detail:'Synthetic problem'}]);
const sourceIssues = p.platformIssueList(p.supplementalIssues(
  {ok:false,error:'HEALTH_MISSING'},{ok:false,error:'ACTIVITY_MISSING'}));
const node = (children={}) => ({
  textContent:'',hidden:true,dataset:{},title:'',
  style:{setProperty(k,v){this[k]=v;}},
  querySelector(k){return children[k] ?? null;}
});
const primary=node(), secondary=node(), detail=node(), value=node(), head=node();
const copy=node({strong:primary,span:secondary,small:detail});
const ring=node({strong:value});
const panel=node({'.ssv2-panel-head b':head,'.ssv2-storage-ring':ring,
                  '.ssv2-storage-copy':copy});
const root=node({'.ssv2-panel.storage':panel});
env.document.getElementById = () => root;
const storagePlugin = env.window.BRVTALManagedStorageStatus;
const applied = storagePlugin.applyStorage({
  ok:true,scope:'brvtal_managed_data',used_percent:25,used:'1 MB',
  quota:'4 MB',free:'3 MB',managed_files:2});
const validStorage = {applied,scope:panel.dataset.storageScope,
  summary:primary.textContent,detail:detail.textContent};
storagePlugin.applyUnavailable();
const missingStorage = {scope:panel.dataset.storageScope,
  summary:primary.textContent,detail:detail.textContent};
const incompleteAccepted = storagePlugin.applyStorage({ok:true,used_percent:9});
const log=node(), meta=node();
p.renderLogPayload({querySelector(k){
  return {'#ssv2-logs':log,'#ssv2-log-meta':meta}[k] ?? null;
}}, {content:'<script>fictional</script>',lines:1,bytes:26});
process.stdout.write(JSON.stringify({
  health:goodHealth,activity:goodActivity,issues,sourceIssues,
  healthUnavailable:p.contentHealthBlock({ok:false,error:'MISSING'}),
  activityUnavailable:p.activityList({ok:false,error:'MISSING'}),
  backlogUnavailable:p.githubBacklog({backlog_state:'unavailable',open_issues:null}),
  escapedError:p.contentHealthBlock({ok:false,error:'<script>fictional</script>'}),
  missingHealth:p.contentHealthBlock({}),
  missingActivity:p.activityList({}),
  missingIssues:p.platformIssueList([]),
  validStorage,missingStorage,incompleteAccepted,log:log.textContent,network
}));
"""


def rows(text: str) -> dict[str, tuple[str, ...]]:
    result: dict[str, tuple[str, ...]] = {}
    for line in text.splitlines():
        match = re.match(r"^\| (S28-R\d{3}) · ", line)
        if not match or match.group(1) not in IDS:
            continue
        clause = match.group(1)
        if clause in result:
            raise ValueError("UNKNOWN: duplicate " + clause)
        cells = tuple(x.strip() for x in line.strip().strip("|").split("|"))
        if len(cells) != 5 or not cells[0].startswith(clause + " · "):
            raise ValueError("UNKNOWN: malformed " + clause)
        result[clause] = cells
    return result


@lru_cache(maxsize=1)
def observe() -> dict[str, object]:
    node = shutil.which("node")
    if not node:
        raise AssertionError("UNKNOWN: Node missing")
    run = subprocess.run([node, "-e", PROBE], cwd=ROOT,
                         text=True, capture_output=True, timeout=20, check=False)
    if run.returncode:
        raise AssertionError("UNKNOWN: isolated status VM failed: " + run.stderr[-700:])
    data = json.loads(run.stdout)
    if not isinstance(data, dict):
        raise AssertionError("UNKNOWN: invalid fixture evidence")
    return data


def sources() -> dict[str, str]:
    return {"status": STATUS.read_text(encoding="utf-8"),
            "storage": STORAGE.read_text(encoding="utf-8"),
            "quota": QUOTA.read_text(encoding="utf-8"),
            "technical": TECHNICAL.read_text(encoding="utf-8")}


def verdict(clause: str, table: dict[str, tuple[str, ...]],
            observed: object, files: dict[str, str]) -> str:
    row = table.get(clause)
    if (clause not in IDS or row is None or len(row) != 5
            or not row[1].startswith(SUMMARY[clause])
            or row[2] != "NO_VERIFICABLE"
            or not isinstance(observed, dict) or observed.get("network") != 0
            or not all(files.get(k) for k in ("status","storage","quota","technical"))):
        return UNKNOWN
    if clause == "S28-R010":
        return LOCAL if ("CONTENT HEALTH" in observed.get("health", "")
                         and "78" in observed["health"]
                         and "SEO GAPS" in observed["health"]
                         and "CONTENT HEALTH UNAVAILABLE" in observed.get("healthUnavailable", "")
                         and "'/api/content-health.php'" in files["status"]) else UNKNOWN
    if clause == "S28-R011":
        return LOCAL if ("UPDATE EVENT" in observed.get("activity", "")
                         and "Fixture record" in observed["activity"]
                         and "Synthetic admin" in observed["activity"]
                         and "ADMIN ACTIVITY UNAVAILABLE" in observed.get("activityUnavailable", "")
                         and "'/api/admin-activity.php?limit=5'" in files["status"]) else UNKNOWN
    if clause == "S28-R012":
        return LOCAL if ("FIXTURE ALERT" in observed.get("issues", "")
                         and "Synthetic problem" in observed["issues"]
                         and "HEALTH_MISSING" in observed.get("sourceIssues", "")
                         and "ACTIVITY_MISSING" in observed["sourceIssues"]
                         and "GITHUB BACKLOG UNAVAILABLE" in observed.get("backlogUnavailable", "")
                         and "platformIssues" in files["status"]) else UNKNOWN
    if clause == "S28-R013":
        # Diagnostics exposes a POST RESET LOG action. The full read-only
        # clause cannot be certified while this action exists.
        return UNKNOWN
    if clause == "S28-R014":
        # The storage renderer can accept ok:true without a verified scope
        # and quota. A valid synthetic display is NOT complete attestation.
        return UNKNOWN
    return UNKNOWN


class BrechasBrvtal36Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        table, observed, files = rows(MATRIX.read_text(encoding="utf-8")), observe(), sources()
        self.assertEqual(set(IDS), set(table))
        self.assertIn("## 28. Dashboard and System Status", SPEC.read_text(encoding="utf-8"))
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertEqual("NO_VERIFICABLE", table[clause][2])
                self.assertEqual(LOCAL if clause in IDS[:3] else UNKNOWN,
                                 verdict(clause, table, observed, files))
        # R013/R014 retain genuine observations even though full coverage is UNKNOWN.
        self.assertIn('<details class="ssv2-advanced">', files["status"])
        self.assertIn('id="ssv2-reset-logs"', files["status"])
        self.assertIn("method:'POST'", files["status"])
        self.assertIn("output.textContent = logContent", files["status"])
        self.assertEqual("<script>fictional</script>", observed["log"])
        self.assertTrue(observed["validStorage"]["applied"])
        self.assertEqual("brvtal-managed-data", observed["validStorage"]["scope"])
        self.assertEqual("1 MB / 4 MB", observed["validStorage"]["summary"])
        self.assertIn("STORAGE_QUOTA_NOT_CONFIGURED", files["quota"])
        self.assertIn("'diagnostic_only' => true", files["quota"])
        self.assertIn("'source'=>'managed_storage_endpoint'", files["technical"])

    def test_falla_cerrado(self) -> None:
        table, observed, files = rows(MATRIX.read_text(encoding="utf-8")), observe(), sources()
        row = next(x for x in MATRIX.read_text(encoding="utf-8").splitlines()
                   if x.startswith("| S28-R010 · "))
        with self.assertRaisesRegex(ValueError, "duplicate"):
            rows(row + "\n" + row)
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertEqual(UNKNOWN, verdict(clause, {}, observed, files))
                self.assertEqual(UNKNOWN, verdict(clause, table, None, files))
                self.assertEqual(UNKNOWN, verdict(clause, table, observed, {}))
                self.assertEqual(UNKNOWN, verdict(clause, table,
                                 {**observed, "network": 1}, files))
        self.assertEqual(UNKNOWN, verdict("S28-R999", table, observed, files))
        self.assertIn("CONTENT HEALTH UNAVAILABLE", observed["healthUnavailable"])
        self.assertIn("ADMIN ACTIVITY UNAVAILABLE", observed["activityUnavailable"])
        self.assertIn("GITHUB BACKLOG UNAVAILABLE", observed["backlogUnavailable"])
        self.assertNotIn("<script>", observed["escapedError"])
        self.assertIn("&lt;script&gt;", observed["escapedError"])
        self.assertEqual("unavailable", observed["missingStorage"]["scope"])
        self.assertIn("MANAGED STORAGE UNAVAILABLE", observed["missingStorage"]["summary"])

    def test_sin_datos_reales(self) -> None:
        observed = observe()
        self.assertEqual(0, observed["network"])
        self.assertIn("Synthetic admin", observed["activity"])
        self.assertIn("Fixture record", observed["activity"])
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own, r"(?m)^(?:from|import)\s+(?:requests|urllib|httpx|socket)\b")
        self.assertNotIn("BEGIN " + "PRIVATE KEY", own)
        self.assertNotIn("curl_exec(", PROBE)
        self.assertNotIn("require('node:https')", PROBE)

    def test_estados_unknown_explicitos(self) -> None:
        table, observed, files = rows(MATRIX.read_text(encoding="utf-8")), observe(), sources()
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertEqual("NO_VERIFICABLE", table[clause][2])
                self.assertEqual(UNKNOWN, verdict(clause, table, None, files))
                self.assertEqual(UNKNOWN, verdict(clause, {}, observed, files))
        # The existing fallback UI treats missing fields as a healthy score,
        # empty activity or no platform signals. Never promote such results.
        self.assertIn("100", observed["missingHealth"])
        self.assertIn("NO RECENT ACTIVITY", observed["missingActivity"])
        self.assertIn("NO ACTIVE PLATFORM SIGNALS", observed["missingIssues"])
        self.assertIsInstance(observed["incompleteAccepted"], bool)
        self.assertEqual(UNKNOWN, verdict("S28-R013", table, observed, files))
        self.assertEqual(UNKNOWN, verdict("S28-R014", table, observed, files))


if __name__ == "__main__":
    unittest.main()
