#!/usr/bin/env python3
"""BRVTAL #1125: offline synthetic checks for S28-R005..R009.

Local UI contracts are not production/runtime attestation. Matrix rows stay
NO_VERIFICABLE pending independent reconciliation and deploy evidence.
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
FILES = {
    "status_ui": "discadmin/system-status-v2.js",
    "technical": "discadmin/technical.php",
    "managed_storage": "discadmin/storage-metrics.php",
    "storage_ui": "discadmin/system-status-storage.js",
    "deployment": "config/deployment.php",
}
IDS = ("S28-R005", "S28-R006", "S28-R007", "S28-R008", "S28-R009")
SUMMARY = {
    "S28-R005": "API/database/runtime/service indicators;",
    "S28-R006": "BRVTAL-managed storage utilization;",
    "S28-R007": "deployment SHA/environment/runtime;",
    "S28-R008": "database content counts;",
    "S28-R009": "GitHub repository metrics;",
}
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"

# Execute production JS with only an injected export of existing pure helpers.
# No HTTP, timers, DOM sessions, runtime credentials or production data.
PROBE = r"""
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('discadmin/system-status-v2.js','utf8');
const anchor = '  const observer = new MutationObserver(';
if (source.split(anchor).length !== 2) throw Error('UNKNOWN: cannot isolate dashboard');
const exposed = source.replace(anchor,
  '  globalThis.__probe = {serviceCards,managedStoragePanel,databaseBars,repositoryBlock,githubBacklog};\n' + anchor);
let network = 0;
const env = {
  Intl, URLSearchParams, window: {},
  document: {documentElement:{},getElementById(){return null;}},
  MutationObserver: class {observe(){}},
  setTimeout(){return 0;},clearTimeout(){},
  fetch(){network++;throw Error('OFFLINE_NETWORK_FORBIDDEN');}
};
vm.runInNewContext(exposed, env, {timeout:1500});
const p = env.__probe;
const github = {open_issues:2,backlog_state:'fresh',recent_issues:[
  {number:14,title:'Synthetic issue',labels:['type: tests']}]};
const repo = {source_files:3,source_lines:41,by_language:{PHP:{lines:41}},
              github:{ok:true,commits:10,merged_prs:5,...github}};
const sha = 'a'.repeat(40);
process.stdout.write(JSON.stringify({
  services:p.serviceCards({database:{server:'fixture-db'},checks:[
    {key:'api',label:'API',status:'ok',value:'ONLINE'},
    {key:'database',label:'DATABASE',status:'ok',value:'CONNECTED'},
    {key:'runtime',label:'PHP RUNTIME',status:'error',value:'CHECK'}]},12),
  servicesMissing:p.serviceCards({},0),
  storage:p.managedStoragePanel({available:true,used_percent:25,
    used:'1 MB',total:'4 MB',free:'3 MB',uploads_items:2}),
  storageMissing:p.managedStoragePanel({available:false,
    host_filesystem:{total:'99 TB'}}),
  storageAmbiguous:p.managedStoragePanel({available:true,used_percent:10}),
  deployment:p.repositoryBlock({repository:repo,deployment:{
    commit:sha,short_commit:sha.slice(0,7),exact:true,source:'environment'}}),
  deploymentMissing:p.repositoryBlock({repository:repo,deployment:{
    commit:sha,short_commit:sha.slice(0,7),exact:false,source:'release_fallback'}}),
  deploymentAmbiguous:p.repositoryBlock({repository:repo,deployment:{
    commit:sha,short_commit:sha.slice(0,7)}}),
  counts:p.databaseBars({events:9,artists:2,sets:1,releases:0,
    media:3,pages:4,blog:5}),
  countsMissing:p.databaseBars({}),
  github:p.githubBacklog(github),
  githubMissing:p.githubBacklog({open_issues:null,backlog_state:'unavailable'}),
  githubStale:p.githubBacklog({...github,backlog_state:'stale'}),
  githubAmbiguous:p.githubBacklog({open_issues:0,backlog_state:'fresh'}),
  network
}));
"""


def rows(text: str) -> dict[str, tuple[str, ...]]:
    found: dict[str, tuple[str, ...]] = {}
    for line in text.splitlines():
        match = re.match(r"^\| (S28-R\d{3}) · ", line)
        if not match or match.group(1) not in IDS:
            continue
        key = match.group(1)
        if key in found:
            raise ValueError("UNKNOWN: duplicate " + key)
        cells = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
        if len(cells) != 5 or not cells[0].startswith(key + " · "):
            raise ValueError("UNKNOWN: malformed " + key)
        found[key] = cells
    return found


@lru_cache(maxsize=1)
def observe() -> dict[str, object]:
    node = shutil.which("node")
    if node is None:
        raise AssertionError("UNKNOWN: local Node missing")
    run = subprocess.run([node, "-e", PROBE], cwd=ROOT, text=True,
                         capture_output=True, check=False, timeout=20)
    if run.returncode:
        raise AssertionError("UNKNOWN: offline VM failed: " + run.stderr[-800:])
    observed = json.loads(run.stdout)
    if not isinstance(observed, dict):
        raise AssertionError("UNKNOWN: invalid fixture")
    return observed


def sources() -> dict[str, str]:
    return {name: (ROOT / path).read_text(encoding="utf-8")
            for name, path in FILES.items()}


def verdict(key: str, table: dict[str, tuple[str, ...]],
            observed: object, files: dict[str, str]) -> str:
    row = table.get(key)
    if (key not in IDS or row is None or len(row) != 5
            or row[2] != "NO_VERIFICABLE"
            or not row[1].startswith(SUMMARY[key])
            or not isinstance(observed, dict) or observed.get("network") != 0
            or not all(isinstance(files.get(n), str) and files[n] for n in FILES)):
        return UNKNOWN

    technical, storage, deployment = (files["technical"],
                                      files["managed_storage"], files["deployment"])
    evidence = {
        "S28-R005": (
            'data-state="ok"' in observed.get("services", "")
            and 'data-state="error"' in observed.get("services", "")
            and "fixture-db" in observed["services"]
            and "PHP RUNTIME" in observed["services"]
            and "['key'=>'api'" in technical
            and "['key'=>'database'" in technical
            and "['key'=>'runtime'" in technical
        ),
        "S28-R006": (
            'data-storage-scope="brvtal-managed-data"' in observed.get("storage", "")
            and "1 MB / 4 MB" in observed["storage"]
            and "99 TB" not in observed["storage"]
            and "STORAGE_QUOTA_NOT_CONFIGURED" in storage
            and "'diagnostic_only' => true" in storage
            and "applyUnavailable" in files["storage_ui"]
        ),
        "S28-R007": (
            "SOURCE aaaaaaa" in observed.get("deployment", "")
            and "SOURCE UNAVAILABLE" in observed.get("deploymentMissing", "")
            and "brvtalDeploymentIsExact" in deployment
            and "brvtal_deployment_source" in technical
        ),
        "S28-R008": (
            "EVENTS" in observed.get("counts", "")
            and "ARTISTS" in observed["counts"]
            and ">9</b>" in observed["counts"]
            and "'events'=>'events'" in technical
            and "'artists'=>'artists'" in technical
        ),
        "S28-R009": (
            "Synthetic issue" in observed.get("github", "")
            and "GITHUB #14" in observed["github"]
            and "GITHUB BACKLOG UNAVAILABLE" in observed.get("githubMissing", "")
            and "CACHED GITHUB DATA" in observed.get("githubStale", "")
            and "backlog_state" in technical
            and "github_public_api" in technical
        ),
    }
    # Exact deployment identity cannot be certified by synthetic rendering:
    # missing exact is accepted by the UI even when a SHA was supplied.
    if key == "S28-R007":
        return UNKNOWN
    return LOCAL if evidence[key] else UNKNOWN


class BrechasBrvtal35Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        table, data, files = rows(MATRIX.read_text(encoding="utf-8")), observe(), sources()
        spec = SPEC.read_text(encoding="utf-8")
        self.assertEqual(set(IDS), set(table))
        for key in IDS:
            with self.subTest(brecha=key):
                self.assertEqual("NO_VERIFICABLE", table[key][2])
                self.assertIn(table[key][1], spec)
                self.assertEqual(UNKNOWN if key == "S28-R007" else LOCAL,
                                 verdict(key, table, data, files))
        self.assertIn("brvtalDeploymentIsExact", files["deployment"])
        self.assertIn("SOURCE aaaaaaa", data["deployment"])
        self.assertIn("SOURCE UNAVAILABLE", data["deploymentMissing"])

    def test_falla_cerrado(self) -> None:
        table, data, files = rows(MATRIX.read_text(encoding="utf-8")), observe(), sources()
        row = next(line for line in MATRIX.read_text(encoding="utf-8").splitlines()
                   if line.startswith("| S28-R005 · "))
        with self.assertRaisesRegex(ValueError, "duplicate"):
            rows(row + "\n" + row)
        with self.assertRaisesRegex(ValueError, "malformed"):
            rows("| S28-R006 · invalid | COVERED |")
        for key in IDS:
            with self.subTest(brecha=key):
                self.assertEqual(UNKNOWN, verdict(key, {}, data, files))
                self.assertEqual(UNKNOWN, verdict(key, table, None, files))
                self.assertEqual(UNKNOWN, verdict(key, table, data, {}))
                self.assertEqual(UNKNOWN, verdict(key, table,
                                 {**data, "network": 1}, files))
                changed = dict(table)
                changed[key] = tuple("CUBIERTO" if i == 2 else s
                                     for i, s in enumerate(table[key]))
                self.assertEqual(UNKNOWN, verdict(key, changed, data, files))
        self.assertEqual(UNKNOWN, verdict("S28-R999", table, data, files))

    def test_sin_datos_reales(self) -> None:
        data = observe()
        self.assertEqual(0, data["network"])
        self.assertIn("Synthetic issue", data["github"])
        self.assertNotIn("99 TB", data["storage"])
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own, r"(?m)^(?:from|import)\s+(?:requests|httpx|socket|urllib)\b")
        self.assertNotIn("BEGIN " + "PRIVATE KEY", own)
        self.assertNotIn("curl_exec(", PROBE)
        self.assertNotIn("https.request(", PROBE)

    def test_estados_unknown_explicitos(self) -> None:
        table, data, files = rows(MATRIX.read_text(encoding="utf-8")), observe(), sources()
        self.assertEqual("", data["servicesMissing"])
        self.assertIn("MANAGED STORAGE UNAVAILABLE", data["storageMissing"])
        self.assertIn("N/A", data["storageAmbiguous"])
        self.assertIn("SOURCE UNAVAILABLE", data["deploymentMissing"])
        self.assertIn("SOURCE aaaaaaa", data["deploymentAmbiguous"])
        self.assertIn(">0</b>", data["countsMissing"])
        self.assertIn("GITHUB BACKLOG UNAVAILABLE", data["githubMissing"])
        self.assertIn("CACHED GITHUB DATA", data["githubStale"])
        self.assertIn("NO OPEN GITHUB ISSUES", data["githubAmbiguous"])
        for key in IDS:
            self.assertEqual(UNKNOWN, verdict(key, table, None, files))
        self.assertEqual(UNKNOWN, verdict("S28-R007", table, data, files))


if __name__ == "__main__":
    unittest.main()
