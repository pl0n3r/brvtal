#!/usr/bin/env python3
"""BRVTAL #1283: executable offline behavior for S35-R008..S38-R009.

Only exercised source behavior is covered here. Hosting facts, deployment,
live browser/DB checks and editorial safety policy require separate evidence.
No coverage-matrix state is read or asserted by this test.
"""
from __future__ import annotations

import json
import shutil
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SYNTHETIC_SHA = "0123456789abcdef0123456789abcdef01234567"
SYNTHETIC_URL = "https://example.invalid"

# Execute the existing storage UI and release observer, not a copy of their logic.
# Every request and timer is synthetic; an actual network call is forbidden.
NODE_PROBE = r"""
const fs = require('node:fs');
const vm = require('node:vm');

async function main() {
  const value = () => ({textContent:'', dataset:{}, title:'',
    style:{setProperty() {}}});
  const head=value(), ringValue=value(), primary=value(), secondary=value(), detail=value();
  const ring=value();
  ring.querySelector = selector => selector === 'strong' ? ringValue : null;
  const copy=value();
  copy.querySelector = selector => ({strong:primary, span:secondary, small:detail})[selector] || null;
  const panel=value();
  panel.querySelector = selector => ({
    '.ssv2-panel-head b':head, '.ssv2-storage-ring':ring, '.ssv2-storage-copy':copy
  })[selector] || null;
  const root = {querySelector: selector => selector === '.ssv2-panel.storage' ? panel : null};
  const sandbox = {
    window:{},
    document:{getElementById: id => id === 'system-status-v2' ? root : null,
      querySelector:()=>null, documentElement:{}},
    MutationObserver:class {observe() {}},
    setTimeout:()=>1, clearTimeout:()=>{},
    fetch:()=>{throw Error('NETWORK_FORBIDDEN');}
  };
  vm.runInNewContext(fs.readFileSync('discadmin/system-status-storage.js', 'utf8'),
                     sandbox, {timeout:2000});
  const ui = sandbox.window.BRVTALManagedStorageStatus;
  const valid = ui.applyStorage({
    ok:true, scope:'brvtal_managed_data', used:'6 MB', quota:'24 MB',
    free:'18 MB', used_percent:25, managed_files:3,
    host_filesystem:{total:'987 TB', free:'986 TB', diagnostic_only:true}
  });
  const visible = {primary:primary.textContent, head:head.textContent,
    detail:detail.textContent, scope:panel.dataset.storageScope,
    title:panel.title};
  const invalid = ui.applyStorage({ok:false, host_filesystem:{total:'987 TB'}});
  const unavailable = ui.applyUnavailable();
  const unavailableView = {scope:panel.dataset.storageScope,
    primary:primary.textContent, head:head.textContent};

  const {observeRelease, observeBeforeAuthenticate} =
    await import('./tests/e2e/production-release-observer.mjs');
  const sha = '0123456789abcdef0123456789abcdef01234567';
  const urls = [];
  const mismatchRequest = {get:async url => {
    urls.push(url);
    return {ok:()=>true, status:()=>200, json:async()=>({data:{
      version:'0.1.13', commit:'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      source:'git_checkout', exact:true
    }})};
  }};
  async function rejected(options, phrase) {
    try { await observeRelease(options); return false; }
    catch (error) { return String(error.message).includes(phrase); }
  }
  const base = {baseUrl:'https://example.invalid',expectedVersion:'0.1.13',
    expectedSha:sha,attempts:1,sleep:async()=>{},now:()=>1};
  const mismatched = await rejected({...base,request:mismatchRequest}, 'does not match');
  const missing = await rejected({...base,request:{get:async()=>{throw Error('OFFLINE_FAILURE');}}},
    'was not observed');
  const forbidden = await rejected({...base,request:{get:async()=>({
    ok:()=>false,status:()=>403,json:async()=>({error:'DENIED'})
  })}}, 'was not observed');
  const order=[];
  await observeBeforeAuthenticate(async()=>{order.push('observe');},
    async()=>{order.push('authenticate');});
  let authenticatedAfterError=false;
  let observedError=false;
  try {
    await observeBeforeAuthenticate(async()=>{throw Error('OBSERVATION_FAILED');},
      async()=>{authenticatedAfterError=true;});
  } catch (error) { observedError=error.message==='OBSERVATION_FAILED'; }
  process.stdout.write(JSON.stringify({
    storage:{valid,visible,invalid,unavailable,unavailableView},
    release:{mismatched,missing,forbidden,order,observedError,
      authenticatedAfterError,urls}
  }));
}
main().catch(error=>{console.error(error);process.exitCode=1;});
"""


def run(*args: str, input_text: str | None = None) -> subprocess.CompletedProcess[str]:
    binary = shutil.which(args[0])
    if not binary:
        raise AssertionError("Required offline interpreter missing: " + args[0])
    return subprocess.run(
        [binary, *args[1:]], cwd=ROOT, input=input_text,
        text=True, capture_output=True, check=False, timeout=25,
    )


@lru_cache(maxsize=1)
def observe() -> dict[str, object]:
    result = run("node", "-e", NODE_PROBE)
    if result.returncode != 0:
        raise AssertionError("Real JS modules failed: " + result.stderr[-600:])
    try:
        data = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError("Incomplete offline JS observation") from exc
    if (not isinstance(data, dict)
            or set(data) != {"storage", "release"}
            or not all(isinstance(data[k], dict) for k in data)):
        raise AssertionError("Ambiguous offline JS observation")
    return data


class CoberturaBrvtal14Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: execute storage, syntax gates, CI contract, release ordering."""
        results = observe()
        storage = results["storage"]
        visible = storage["visible"]
        self.assertTrue(storage["valid"], "S36-R003: real storage module")
        self.assertEqual(visible["scope"], "brvtal-managed-data")
        self.assertEqual(visible["primary"], "6 MB / 24 MB")
        self.assertIn("configured quota", visible["title"])
        self.assertNotIn("987 TB", json.dumps(visible))
        self.assertEqual(visible["head"], "25.0% USED · BRVTAL DATA")

        # S38-L592 and S38-R002: real PHP/JS parsers validate actual files.
        php_file = (ROOT / "config/version.php").read_text(encoding="utf-8")
        js_file = (ROOT / "discadmin/system-status-storage.js").read_text(encoding="utf-8")
        self.assertEqual(run("php", "-l", input_text=php_file).returncode, 0)
        self.assertEqual(run("node", "--check", input_text=js_file).returncode, 0)

        # S38-R003: execute the already-shipped real Factory caller contract.
        contract = run("php", "tests/factory-ci-contract.php")
        self.assertEqual(contract.returncode, 0, contract.stderr[-500:])
        self.assertIn("Factory CI caller contract passed.", contract.stdout)

        # S38-R007 and R008: release observer refuses wrong SHA and preserves order.
        release = results["release"]
        self.assertTrue(release["mismatched"])
        self.assertEqual(release["order"], ["observe", "authenticate"])

    def test_falla_cerrado(self) -> None:
        """AC-02: unavailable quota, denied probe and invalid syntax fail closed."""
        results = observe()
        storage, release = results["storage"], results["release"]
        self.assertFalse(storage["invalid"])
        self.assertTrue(storage["unavailable"])
        self.assertEqual(storage["unavailableView"]["scope"], "unavailable")
        self.assertEqual(storage["unavailableView"]["primary"],
                         "MANAGED STORAGE UNAVAILABLE")
        self.assertTrue(release["missing"])
        self.assertTrue(release["forbidden"])
        self.assertTrue(release["observedError"])
        self.assertFalse(release["authenticatedAfterError"])
        self.assertNotEqual(run("php", "-l",
                                input_text="<?php function {").returncode, 0)
        self.assertNotEqual(run("node", "--check",
                                input_text="const = ;").returncode, 0)

    def test_sin_datos_reales(self) -> None:
        """AC-03: synthetic identity, no real HTTP, credentials or DB."""
        release = observe()["release"]
        self.assertEqual(len(SYNTHETIC_SHA), 40)
        self.assertTrue(all(c in "0123456789abcdef" for c in SYNTHETIC_SHA))
        self.assertTrue(SYNTHETIC_URL.endswith(".invalid"))
        self.assertEqual(len(release["urls"]), 1)
        self.assertTrue(release["urls"][0].startswith(
            SYNTHETIC_URL + "/api/deployment.php?"))
        self.assertIn("NETWORK_FORBIDDEN", NODE_PROBE)
        self.assertNotIn("process.env", NODE_PROBE)
        self.assertNotIn("fetch(", NODE_PROBE.replace(
            "fetch:()=>{throw Error('NETWORK_FORBIDDEN');}", ""))


if __name__ == "__main__":
    unittest.main()
