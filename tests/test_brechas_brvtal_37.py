#!/usr/bin/env python3
"""BRVTAL #1127: contratos offline S28-R015..R019 (System Status/Development).

A local proof is not deployment, account-quota verification, production
diagnostic authorization or public-log exposure certification. The coverage
matrix intentionally remains NO_VERIFICABLE until a separate reconciliation.
"""
from __future__ import annotations

import json
import re
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
SPEC = ROOT / "docs/BRVTAL-SPEC.md"
IDS = ("S28-R015", "S28-R016", "S28-R017", "S28-R018", "S28-R019")
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"
FILES = {
    "storage_endpoint": "discadmin/storage-metrics.php",
    "storage_ui": "discadmin/system-status-storage.js",
    "status": "discadmin/status.php",
    "technical": "discadmin/technical.php",
    "logs": "discadmin/logs.php",
    "protected": "storage/.htaccess",
    "development": "config/admin_development_signals.php",
    "development_api": "api/admin-development-signals.php",
    "dashboard": "discadmin/dashboard-v2.js",
    "dashboard_catalog": "config/admin_dashboard.php",
}


def coverage_rows(source: str) -> dict[str, tuple[str, ...]]:
    rows: dict[str, tuple[str, ...]] = {}
    for line in source.splitlines():
        match = re.match(r"^\| (S28-R\d{3}) · ", line)
        if not match or match.group(1) not in IDS:
            continue
        key = match.group(1)
        if key in rows:
            raise ValueError("duplicate coverage row: " + key)
        cells = tuple(x.strip() for x in line.strip().strip("|").split("|"))
        if len(cells) != 5 or not cells[0].startswith(key + " · "):
            raise ValueError("malformed coverage row: " + key)
        rows[key] = cells
    return rows


def sources() -> dict[str, str]:
    return {key: (ROOT / path).read_text(encoding="utf-8")
            for key, path in FILES.items()}


@lru_cache(maxsize=9)
def github_probe(mode: str = "normal") -> dict[str, object]:
    """Execute the actual PHP adapter using ONLY an injected, offline callback."""
    if mode not in {"normal", "empty", "failed", "badcount", "incomplete",
                    "invalidtime", "stale", "pending", "noci"}:
        raise ValueError("unrecognized synthetic scenario")
    script = r"""
require 'config/admin_development_signals.php';
$mode = MODE;
$calls = [];
$request = static function (string $url) use ($mode, &$calls): array {
    $calls[] = $url;
    if ($mode === 'failed') {
        throw new RuntimeException('SYNTHETIC_OFFLINE_FAILURE');
    }
    if (str_contains($url, 'is%3Aissue')) {
        return ['status'=>200, 'body'=>json_encode([
            'total_count'=>$mode === 'badcount' ? '7' : ($mode === 'empty' ? 0 : 7),
            'incomplete_results'=>$mode === 'incomplete', 'items'=>[],
        ], JSON_THROW_ON_ERROR)];
    }
    if (str_contains($url, 'is%3Apr')) {
        return ['status'=>200, 'body'=>json_encode([
            'total_count'=>$mode === 'empty' ? 0 : 2,
            'incomplete_results'=>false,
            'items'=>$mode === 'empty' ? [] : [[
                'number'=>123, 'title'=>'Synthetic PR',
                'html_url'=>'https://github.com/pl0n3r/brvtal/pull/123'
            ]],
        ], JSON_THROW_ON_ERROR)];
    }
    if (!str_contains(
        $url, '/repos/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/runs'
    )) {
        throw new RuntimeException('UNEXPECTED_GITHUB_ROUTE');
    }
    $updated = $mode === 'invalidtime' ? 'not-a-date' : (
        $mode === 'stale'
            ? gmdate('c', time() - BRVTAL_DEVELOPMENT_STALE_SECONDS - 60)
            : gmdate('c')
    );
    $runs = $mode === 'noci' || $mode === 'empty' ? [] : [[
        'status'=>$mode === 'pending' ? 'in_progress' : 'completed',
        'conclusion'=>$mode === 'pending' ? null : 'success',
        'html_url'=>'https://github.com/pl0n3r/brvtal/actions/runs/1',
        'updated_at'=>$updated,
    ]];
    return ['status'=>200, 'body'=>json_encode(
        ['workflow_runs'=>$runs], JSON_THROW_ON_ERROR
    )];
};
echo json_encode([
    'result'=>brvtalAdminDevelopmentSignals($request),
    'calls'=>$calls,
], JSON_THROW_ON_ERROR);
""".replace("MODE", json.dumps(mode), 1)
    run = subprocess.run(["php", "-r", script], cwd=ROOT,
                         text=True, capture_output=True, timeout=20,
                         check=False)
    if run.returncode:
        raise AssertionError("offline PHP development adapter failed: " + run.stderr)
    return json.loads(run.stdout)


@lru_cache(maxsize=1)
def storage_probe() -> dict[str, object]:
    """Evaluate the real storage UI in an isolated Node VM, with no fetch."""
    code = r"""
const fs = require('node:fs');
const vm = require('node:vm');
const labels = {};
function node() {return {textContent:'',style:{setProperty(k,v){this[k]=v;}}};}
for (const k of ['head','ringValue','primary','secondary','detail']) labels[k] = node();
const ring = node();
ring.querySelector = (selector) => selector === 'strong' ? labels.ringValue : null;
const copy = {querySelector(selector) {
    return ({strong:labels.primary,span:labels.secondary,small:labels.detail})[selector];
}};
const panel = {
    dataset:{},title:'',
    querySelector(selector) {
        return ({
            '.ssv2-panel-head b':labels.head,
            '.ssv2-storage-ring':ring,
            '.ssv2-storage-copy':copy
        })[selector] || null;
    }
};
const root = {querySelector(selector) {
    return selector === '.ssv2-panel.storage' ? panel : null;
}};
const sandbox = {
    window:{},document:{
        getElementById(id){return id === 'system-status-v2' ? root : null;},
        querySelector(){return null;},documentElement:{}
    },
    MutationObserver:class {observe() {}},
    setTimeout(){return 1;},clearTimeout(){},
    fetch(){throw Error('NETWORK_FORBIDDEN');}
};
vm.runInNewContext(fs.readFileSync('discadmin/system-status-storage.js','utf8'),
                   sandbox, {timeout:1500});
const ui = sandbox.window.BRVTALManagedStorageStatus;
const ok = ui.applyStorage({
    ok:true,scope:'brvtal_managed_data',used:'20 MB',quota:'100 MB',
    free:'80 MB',used_percent:20,managed_files:3,
    host_filesystem:{total:'99 TB',free:'98 TB',diagnostic_only:true}
});
const visible = {
    scope:panel.dataset.storageScope,primary:labels.primary.textContent,
    head:labels.head.textContent,detail:labels.detail.textContent,
    title:panel.title
};
const invalid = ui.applyStorage({ok:false,host_filesystem:{total:'99 TB'}});
const unavailable = ui.applyUnavailable();
process.stdout.write(JSON.stringify({
    ok,visible,invalid,unavailable,
    unavailable_scope:panel.dataset.storageScope,
    unavailable_label:labels.primary.textContent,
    unavailable_head:labels.head.textContent
}));
"""
    run = subprocess.run(["node", "-e", code], cwd=ROOT,
                         text=True, capture_output=True, timeout=20,
                         check=False)
    if run.returncode:
        raise AssertionError("offline Node storage contract failed: " + run.stderr)
    return json.loads(run.stdout)


def verdict(key: str, rows: dict[str, tuple[str, ...]],
            src: dict[str, str], github: object, storage: object) -> dict[str, str]:
    state = {"code": UNKNOWN, "ci": UNKNOWN, "production": UNKNOWN,
             "full_scope": UNKNOWN}
    row = rows.get(key)
    if (key not in IDS or row is None or len(row) != 5
            or row[2] != "NO_VERIFICABLE"
            or not all(isinstance(src.get(k), str) and src[k] for k in FILES)
            or not isinstance(github, dict) or not isinstance(storage, dict)):
        return state

    storage_endpoint, storage_ui = src["storage_endpoint"], src["storage_ui"]
    technical, status, logs, protected = (
        src["technical"], src["status"], src["logs"], src["protected"])
    dev, api, dashboard, catalog = (src["development"], src["development_api"],
                                    src["dashboard"], src["dashboard_catalog"])
    result = github.get("result")
    calls = github.get("calls")
    if not isinstance(result, dict) or not isinstance(calls, list):
        return state

    checks = {
        "S28-R015": (
            storage.get("ok") is True
            and storage["visible"]["scope"] == "brvtal-managed-data"
            and storage["visible"]["primary"] == "20 MB / 100 MB"
            and "99 TB" not in json.dumps(storage["visible"])
            and storage.get("invalid") is False
            and storage.get("unavailable") is True
            and storage.get("unavailable_scope") == "unavailable"
            and "UNAVAILABLE" in storage.get("unavailable_label", "")
            and "'diagnostic_only' => true" in storage_endpoint
            and "'scope' => 'brvtal_managed_data'" in storage_endpoint
            and "'error' => 'STORAGE_QUOTA_NOT_CONFIGURED'" in storage_endpoint
            and "if ($quota <= 0)" in storage_endpoint
            and "brvtal_admin_require();" in storage_endpoint
            and "applyUnavailable()" in storage_ui
        ),
        # R016 includes a negative, cross-system claim. We can prove a bounded
        # authenticated diagnostic surface, NOT the absence of every possible
        # production auto-repair. Explicitly record that missing proof.
        "S28-R016": False,
        "S28-R017": (
            "brvtal_admin_require();" in logs
            and "ini_set('display_errors', '0')" in logs
            and "X-Robots-Tag: noindex, nofollow, noarchive" in logs
            and "htmlspecialchars($value, ENT_QUOTES, 'UTF-8')" in logs
            and "<?=h($line)?>" in logs
            and "Options -Indexes" in protected
            and r"\.(php|sql|env|log|json)$" in protected
            and "Require all denied" in protected
            and "brvtal_admin_require();" in technical
            and "ini_set('display_errors', '0')" in status
        ),
        "S28-R018": (
            "brvtal_admin_require();" in api
            and "'REQUEST_METHOD' ?? 'GET'" in api
            and "'METHOD_NOT_ALLOWED'" in api
            and "'Cache-Control'=>'no-store'" in api
            and "development:'/api/admin-development-signals.php'" in dashboard
            and "development:developmentPanel" in dashboard
            and "function developmentPanel(sources)" in dashboard
            and "Independent read-only development signals" in dashboard
            and "'development' =>" in catalog
            and result.get("read_only") is True
        ),
        "S28-R019": (
            result.get("status") == "available"
            and result.get("open_issues") == 7
            and result.get("open_prs") == 2
            and result.get("latest_pr", {}).get("number") == 123
            and result.get("latest_ci", {}).get("conclusion") == "success"
            and result.get("freshness") == "fresh"
            and isinstance(result.get("source_at"), str)
            and len(calls) == 3
            and all(url.startswith("https://api.github.com/") for url in calls)
            and any("is%3Aissue" in url for url in calls)
            and any("is%3Apr" in url for url in calls)
            and any("update-release-metadata.yml/runs" in url for url in calls)
            and "BRVTAL_DEVELOPMENT_REPO = 'pl0n3r/brvtal'" in dev
        ),
    }
    if checks[key]:
        state["code"] = LOCAL
    return state


class BrechasBrvtal37Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: 5 rows, 4 local checks and 1 explicit unverified boundary."""
        rows = coverage_rows(MATRIX.read_text(encoding="utf-8"))
        src, github, storage = sources(), github_probe(), storage_probe()
        spec = SPEC.read_text(encoding="utf-8")
        self.assertEqual(set(rows), set(IDS))
        for key in IDS:
            with self.subTest(brecha=key):
                self.assertEqual(rows[key][2], "NO_VERIFICABLE")
                self.assertIn(key, rows[key][0])
                self.assertIn(rows[key][1], spec.replace("`", ""))
                self.assertIn("Sin aserción integral confirmada", rows[key][3])
                expected = UNKNOWN if key == "S28-R016" else LOCAL
                self.assertEqual(verdict(key, rows, src, github, storage)["code"],
                                 expected, key)
        self.assertIn("Diagnostics may detect and recommend but should not "
                      "autonomously repair production.", spec)
        self.assertIn("brvtal_admin_require();", src["technical"])
        self.assertIn("function brvtalAdminDevelopmentSignals(", src["development"])

    def test_falla_cerrado(self) -> None:
        """AC-02: absent, forged, stale or invalid evidence is not success."""
        rows = coverage_rows(MATRIX.read_text(encoding="utf-8"))
        src, github, storage = sources(), github_probe(), storage_probe()
        for key in IDS:
            with self.subTest(missing=key):
                self.assertEqual(verdict(key, {}, src, github, storage)["code"],
                                 UNKNOWN)
                changed = dict(rows)
                changed[key] = tuple("CUBIERTO" if i == 2 else cell
                                     for i, cell in enumerate(rows[key]))
                self.assertEqual(verdict(key, changed, src, github, storage)["code"],
                                 UNKNOWN)
        self.assertEqual(verdict(IDS[0], rows, {}, github, storage)["code"], UNKNOWN)
        self.assertEqual(verdict(IDS[0], rows, src, None, storage)["code"], UNKNOWN)
        self.assertEqual(verdict(IDS[0], rows, src, github, None)["code"], UNKNOWN)
        original = next(x for x in MATRIX.read_text(encoding="utf-8").splitlines()
                        if x.startswith("| S28-R015 · "))
        with self.assertRaisesRegex(ValueError, "duplicate"):
            coverage_rows(original + "\n" + original)
        with self.assertRaisesRegex(ValueError, "malformed"):
            coverage_rows("| S28-R019 · invalid | CUBIERTO |")
        for mode in ("failed", "badcount", "incomplete", "invalidtime"):
            with self.subTest(source=mode):
                degraded = github_probe(mode)["result"]
                self.assertEqual(degraded["status"], "unavailable")
                self.assertEqual(degraded["freshness"], "unavailable")
                self.assertIsNone(degraded["open_issues"])
                self.assertIsNone(degraded["latest_ci"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: mock GitHub/DOM, no network, user data or live credentials."""
        github = github_probe()
        storage = storage_probe()
        self.assertTrue(github["result"]["read_only"])
        self.assertTrue(storage["unavailable"])
        self.assertEqual(github["calls"][0].split("/", 3)[:3],
                         ["https:", "", "api.github.com"])
        self.assertNotIn("99 TB", json.dumps(storage["visible"]))
        self.assertNotIn("Authorization", json.dumps(github["result"]))
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotIn("-----BEGIN " + "PRIVATE KEY-----", own)
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|httpx|socket|urllib)\b")
        self.assertNotIn("curl_" + "exec(", own)
        self.assertNotIn("db" + "();", own)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: code evidence never certifies CI, all diagnostics or live."""
        rows = coverage_rows(MATRIX.read_text(encoding="utf-8"))
        src, github, storage = sources(), github_probe(), storage_probe()
        for key in IDS:
            with self.subTest(brecha=key):
                state = verdict(key, rows, src, github, storage)
                self.assertEqual(state["ci"], UNKNOWN)
                self.assertEqual(state["production"], UNKNOWN)
                self.assertEqual(state["full_scope"], UNKNOWN)
                if key == "S28-R016":
                    self.assertEqual(state["code"], UNKNOWN)
        empty = github_probe("noci")["result"]
        self.assertIsNone(empty["latest_ci"])
        self.assertIsNone(empty["source_at"])
        self.assertEqual(empty["freshness"], "unavailable")
        self.assertEqual(github_probe("stale")["result"]["freshness"], "stale")
        pending = github_probe("pending")["result"]
        self.assertEqual(pending["latest_ci"]["status"], "in_progress")
        self.assertIsNone(pending["latest_ci"]["conclusion"])
        self.assertNotEqual(pending["latest_ci"]["conclusion"], "success")


if __name__ == "__main__":
    unittest.main()
