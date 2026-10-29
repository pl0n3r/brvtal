#!/usr/bin/env python3
"""BRVTAL #1124: synthetic offline S27/S28 evidence; no production claim.

Rows without a fully exercisable contract deliberately retain UNKNOWN.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
FILES = {
    "spec": "docs/BRVTAL-SPEC.md",
    "restore": "discadmin/event-version-restore.js",
    "editor": "discadmin/content-core.js",
    "activity": "discadmin/admin-activity.js",
    "status": "discadmin/system-status-v2.js",
    "technical": "discadmin/technical.php",
    "shell": "discadmin/index.php",
}
IDS = ("S27-R009", "S27-R010", "S28-R001", "S28-R003", "S28-R004")
SUMMARY = {
    "S27-R009": "Cancel or close before Save discards the staged restore.",
    "S27-R010": "One-click restore, lifecycle rollback, relation restore and restore from NEW EVENT remain out of scope.",
    "S28-R001": "Dashboard must be operationally useful, not decorative.",
    "S28-R003": "It centralizes:",
    "S28-R004": "platform health score;",
}
LOCAL = "LOCAL_CONTRACT"
UNKNOWN = "UNKNOWN"

# Execute two real production JS modules in an offline VM, with synthetic
# fixtures and no browser, network, credentials or production context.
NODE_PROBE = r"""
'use strict';
const fs=require('node:fs'),vm=require('node:vm');
const restore=fs.readFileSync('discadmin/event-version-restore.js','utf8');
const status=fs.readFileSync('discadmin/system-status-v2.js','utf8');
let network=0,writes=0;
const win={};
vm.runInNewContext(restore,{window:win,Object,Set,Number,String,Array,console},{timeout:1800});
const planner=win.BRVTALEventVersionRestore;
const current={id:7,title:'Fixture current',status:'published',
  published_at:'2026-10-01',lineup:[{artist_id:4}],ticket_types:[{id:9}]};
const snapshot={id:7,title:'Fixture earlier',status:'draft',
  published_at:null,lineup:undefined,changed_fields:'title'};
const history={id:50,resource:'events',resource_id:7,action:'update',
  created_at:'2026-09-01',after:snapshot};
const prior=JSON.stringify(current);
const plan=planner.buildPlan(history,current);
const invalid=[
  {...history,resource_id:8},
  {...history,action:'create'},
  {...history,action:'delete'},
  {...history,after:{id:8,title:'foreign'}},
  {...history,after:{id:7,title:'Fixture',ticket_types:[{id:2}]}},
  {...history,after:{id:7,title:{nested:true}}},
  {...history,after:{id:7,status:'draft'}},
  {...history,after:null}
].map(item=>planner.buildPlan(item,current).code);
const anchor='  const observer = new MutationObserver(';
if(status.split(anchor).length!==2)throw Error('UNKNOWN: dashboard boundary');
const exposed=status.replace(anchor,
  '  globalThis.__statusProbe={ring,serviceCards,platformIssueList,githubBacklog,managedStoragePanel};\n'+anchor);
const env={
  Intl,URLSearchParams,window:{},
  document:{documentElement:{},getElementById(){return null;}},
  MutationObserver:class{observe(){}},
  setTimeout(){return 0;},clearTimeout(){},
  fetch(){network++;throw Error('OFFLINE_NETWORK_FORBIDDEN');}
};
vm.runInNewContext(exposed,env,{timeout:1800});
const p=env.__statusProbe;
process.stdout.write(JSON.stringify({
  restore:{
    valid:plan.ok,execution:plan.execution,
    fields:plan.ok?Array.from(plan.fields):[],
    title:plan.ok?plan.values.title:null,
    statusExcluded:plan.ok&&!Object.hasOwn(plan.values,'status'),
    relationsExcluded:plan.ok&&!Object.hasOwn(plan.values,'ticket_types'),
    unchanged:JSON.stringify(current)===prior,
    invalid
  },
  health:p.ring(83,'DEGRADED','5/6 checks'),
  missing:p.ring(0,'UNKNOWN','0/0 checks'),
  services:p.serviceCards({database:{server:'fixture-db'},checks:[
    {key:'api',label:'API',status:'ok',value:'ONLINE'},
    {key:'database',label:'DATABASE',status:'error',value:'CHECK'}]},12),
  issues:p.platformIssueList([{severity:'error',title:'Synthetic failure',
    detail:'Check database'}]),
  emptyIssues:p.platformIssueList([]),
  backlog:p.githubBacklog({open_issues:null,backlog_state:'unavailable'}),
  storage:p.managedStoragePanel({available:false}),
  network,writes
}));
"""


def rows(document: object) -> dict[str, tuple[str, ...]]:
    if not isinstance(document, str) or not document:
        raise ValueError("UNKNOWN: coverage matrix missing")
    found: dict[str, tuple[str, ...]] = {}
    for line in document.splitlines():
        match = re.match(r"^\| (S(?:27|28)-R\d{3}) · ", line)
        if not match or match.group(1) not in IDS:
            continue
        key = match.group(1)
        if key in found:
            raise ValueError("UNKNOWN: duplicate " + key)
        cells = tuple(x.strip() for x in line.strip("|").split("|"))
        if (len(cells) != 5 or not cells[0].startswith(key + " · ")
                or cells[1] != SUMMARY[key] or cells[2] != "NO_VERIFICABLE"
                or cells[4] != "—" or not cells[3]):
            raise ValueError("UNKNOWN: malformed " + key)
        found[key] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: missing rows")
    return found


def sources() -> dict[str, str]:
    return {name: (ROOT / path).read_text(encoding="utf-8")
            for name, path in FILES.items()}


@lru_cache(maxsize=1)
def observe() -> dict[str, object]:
    node = shutil.which("node")
    if not node:
        raise AssertionError("UNKNOWN: Node CLI unavailable")
    p = subprocess.run([node, "-e", NODE_PROBE], cwd=ROOT, check=False,
                       text=True, capture_output=True, timeout=25)
    if p.returncode or p.stderr.strip():
        raise AssertionError("UNKNOWN: offline VM failed: " + p.stderr[-900:])
    value = json.loads(p.stdout)
    if not isinstance(value, dict):
        raise AssertionError("UNKNOWN: invalid fixture")
    return value


def verdict(key: object, table: object, files: object,
            data: object) -> dict[str, str]:
    result = {"local": UNKNOWN, "full_scope": UNKNOWN,
              "ci": UNKNOWN, "production": UNKNOWN}
    if (not isinstance(key, str) or key not in IDS
            or not isinstance(table, dict) or key not in table
            or not isinstance(files, dict) or set(files) != set(FILES)
            or not isinstance(data, dict)
            or any(not isinstance(src, str) or not src.strip()
                   for src in files.values())):
        return result
    row = table[key]
    if (not isinstance(row, tuple) or len(row) != 5
            or row[1] != SUMMARY[key] or row[2] != "NO_VERIFICABLE"
            or SUMMARY[key] not in files["spec"]
            or data.get("network") != 0 or data.get("writes") != 0):
        return result
    restored = data.get("restore")
    if not isinstance(restored, dict):
        return result
    exclusions = (restored.get("valid") is True
                  and restored.get("execution") is False
                  and restored.get("fields") == ["title"]
                  and restored.get("title") == "Fixture earlier"
                  and restored.get("statusExcluded") is True
                  and restored.get("relationsExcluded") is True
                  and restored.get("unchanged") is True
                  and restored.get("invalid") == [
                      "EVENT_MISMATCH", "UNSUPPORTED_HISTORY_ACTION",
                      "UNSUPPORTED_HISTORY_ACTION", "EVENT_MISMATCH",
                      "INCOMPATIBLE_SNAPSHOT", "INCOMPATIBLE_SNAPSHOT",
                      "NO_SAFE_CHANGES", "INCOMPATIBLE_SNAPSHOT"
                  ])
    evidence = {
        # The pure restore planner does not certify that Cancel/Close resets
        # the mounted editor (nor that no server save occurred). UNKNOWN.
        "S27-R009": False,
        "S27-R010": (
            exclusions
            and "Number(currentEvent?.id||0)!==eventId" in files["editor"]
            and "eventId<1||!modal?.classList.contains('open')" in files["editor"]
            and "restoreOptions?'review-before-save restore available':'read-only'" in files["activity"]
            and "saveEvent(" not in files["restore"]
        ),
        # The dashboard's overall usefulness and complete centralization
        # require independently observable integration evidence. UNKNOWN.
        "S28-R001": False,
        "S28-R003": False,
        "S28-R004": (
            "<strong>83%</strong>" in data.get("health", "")
            and "DEGRADED" in data.get("health", "")
            and "<strong>0%</strong>" in data.get("missing", "")
            and "UNKNOWN" in data.get("missing", "")
            and 'data-state="error"' in data.get("services", "")
            and "Synthetic failure" in data.get("issues", "")
            and "NO ACTIVE PLATFORM SIGNALS" in data.get("emptyIssues", "")
            and "GITHUB BACKLOG UNAVAILABLE" in data.get("backlog", "")
            and "MANAGED STORAGE UNAVAILABLE" in data.get("storage", "")
            and "$score = (int)round(($okCount / count($checks)) * 100);" in files["technical"]
            and "ring(data.health?.score || 0,statusText," in files["status"]
        ),
    }
    if evidence[key]:
        result["local"] = LOCAL
    return result


class BrechasBrvtal34Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        table, files, data = rows(MATRIX.read_text(encoding="utf-8")), sources(), observe()
        self.assertEqual(set(IDS), set(table))
        for key in IDS:
            with self.subTest(row=key):
                self.assertEqual(SUMMARY[key], table[key][1])
                self.assertIn(SUMMARY[key], files["spec"])
                expected = LOCAL if key in ("S27-R010", "S28-R004") else UNKNOWN
                self.assertEqual(expected, verdict(key, table, files, data)["local"])
        self.assertTrue(data["restore"]["unchanged"])
        self.assertIn("Synthetic failure", data["issues"])

    def test_falla_cerrado(self) -> None:
        raw = MATRIX.read_text(encoding="utf-8")
        table, files, data = rows(raw), sources(), observe()
        sample = next(x for x in raw.splitlines() if x.startswith("| S27-R009 · "))
        duplicate = raw + "\n" + sample
        missing = "\n".join(x for x in raw.splitlines()
                            if not x.startswith("| S28-R004 · "))
        malformed = raw.replace(sample, sample.replace("NO_VERIFICABLE", "CUBIERTO"))
        with self.assertRaisesRegex(ValueError, "duplicate"):
            rows(duplicate)
        with self.assertRaisesRegex(ValueError, "missing"):
            rows(missing)
        with self.assertRaisesRegex(ValueError, "malformed"):
            rows(malformed)
        for key in IDS:
            with self.subTest(row=key):
                self.assertEqual(UNKNOWN, verdict(key, {}, files, data)["local"])
                self.assertEqual(UNKNOWN, verdict(key, table, {}, data)["local"])
                self.assertEqual(UNKNOWN, verdict(key, table, files, None)["local"])
                changed = dict(table)
                changed[key] = (table[key][0], table[key][1], "CUBIERTO",
                                table[key][3], table[key][4])
                self.assertEqual(UNKNOWN, verdict(key, changed, files, data)["local"])
                self.assertEqual(UNKNOWN, verdict(key, table, files,
                                 {**data, "network": 1})["local"])
        bad = {**data, "restore": {**data["restore"], "execution": True}}
        self.assertEqual(UNKNOWN, verdict("S27-R010", table, files, bad)["local"])
        self.assertEqual(UNKNOWN, verdict("S99-R999", table, files, data)["local"])

    def test_sin_datos_reales(self) -> None:
        data = observe()
        self.assertEqual((data["network"], data["writes"]), (0, 0))
        self.assertEqual("Fixture earlier", data["restore"]["title"])
        self.assertNotIn("Fixture earlier", data["health"])
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own, r"(?m)^(?:from|import)\s+(?:requests|httpx|socket|urllib)\b")
        self.assertNotIn("BEGIN " + "PRIVATE KEY", own)
        self.assertNotIn("https.request(", NODE_PROBE)

    def test_estados_unknown_explicitos(self) -> None:
        table, files, data = rows(MATRIX.read_text(encoding="utf-8")), sources(), observe()
        self.assertIn("GITHUB BACKLOG UNAVAILABLE", data["backlog"])
        self.assertIn("MANAGED STORAGE UNAVAILABLE", data["storage"])
        for key in IDS:
            with self.subTest(row=key):
                status = verdict(key, table, files, data)
                self.assertEqual(UNKNOWN, status["full_scope"])
                self.assertEqual(UNKNOWN, status["ci"])
                self.assertEqual(UNKNOWN, status["production"])
                self.assertEqual(UNKNOWN, verdict(key, table, files, {})["local"])
        for key in ("S27-R009", "S28-R001", "S28-R003"):
            self.assertEqual(UNKNOWN, verdict(key, table, files, data)["local"])


if __name__ == "__main__":
    unittest.main()
