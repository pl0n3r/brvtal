#!/usr/bin/env python3
"""BRVTAL #1282: real PHP/JS boundaries with synthetic, offline evidence only.

A passing contract here is *not* a production backup, restore or Hostinger
deployment observation. The coverage matrix is intentionally not inspected.
"""
from __future__ import annotations

from functools import lru_cache
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
A = frozenset({
    "S33-R012", "S33-R013", "S33-R014", "S33-R015", "S33-R016",
    "S34-R004", "S34-R005", "S34-R007",
})
B = {
    "S35-R004": "actual GitHub-to-Hostinger deploy needs observed hosting receipt",
    "S35-R007": "full delivery needs deployment and production validation",
}
C = {
    "S33-R017": "sensitive restore safeguards require a separate approved workflow",
    "S34-R002": "v0.1.0 PRODUCTION is illustrative output, not a behavior",
    "S34-R003": "DEPLOY abc1234 is illustrative output, not a behavior",
    "S34-R006": "intentional metadata edits are release governance",
    "S34-R008": "no semantic-version bump per commit is a release policy",
    "S35-R001": "repository owner and name are governance metadata",
    "S35-R002": "canonical main branch is repository policy",
    "S35-R003": "GitHub source-of-truth precedence is governance",
    "S35-R005": "FTP prohibition is a delivery policy",
    "S35-R006": "manual deployment needs explicit owner authorization",
}
D: dict[str, str] = {}
ALL = A | B.keys() | C.keys() | D.keys()
UNKNOWN = "UNKNOWN"
EXACT_SHA = "0123456789abcdef0123456789abcdef01234567"
PRIVATE = ("SYNTHETIC_PRIVATE_FILENAME", "SYNTHETIC_PRIVATE_PATH",
           "SYNTHETIC_CHECKSUM", "SYNTHETIC_REMOTE_FOLDER",
           "SYNTHETIC_SECRET_TOKEN")

# Directly calls config/admin_backup_signals.php using injected read-only
# source callbacks. No storage provider, database, network or scheduler starts.
BACKUP_PHP = r"""
require 'config/admin_backup_signals.php';
$mode = $argv[1];
$backups = static function() use ($mode): array {
    if ($mode === 'source_failure') throw new RuntimeException('synthetic source');
    if ($mode === 'missing') return [];
    return [[
        'id'=>'brvtal-20260930T120000Z-deadbeef',
        'status'=>$mode === 'partial' ? 'partial' : 'ready',
        'scope'=>'full', 'trigger'=>'scheduled',
        'created_at'=>'2026-09-30T12:00:00Z',
        'artifacts_bytes'=>$mode === 'invalid_manifest' ? '4096' : 4096,
        'filename'=>'SYNTHETIC_PRIVATE_FILENAME',
        'path'=>'SYNTHETIC_PRIVATE_PATH',
        'components'=>['database'=>['sha256'=>'SYNTHETIC_CHECKSUM']],
    ]];
};
$automation = static function() use ($mode): array {
    if ($mode === 'invalid_automation') return [];
    $offsite = in_array($mode, ['offsite_pending','offsite_failed',
        'offsite_success','offsite_secret_error'], true);
    $drive = $mode === 'offsite_success' ? 'success'
        : ($mode === 'offsite_failed' || $mode === 'offsite_secret_error'
            ? 'failed' : 'unknown');
    return [
        'config'=>['enabled'=>true,
                   'drive'=>['enabled'=>$offsite,'folder'=>'SYNTHETIC_REMOTE_FOLDER']],
        'next_run_at'=>'2026-10-01T08:00:00Z',
        'last_success_at'=>'2026-09-30T12:00:00Z',
        'last_result'=>['status'=>$mode === 'scheduler_failed' ? 'failed' : 'success',
                        'drive'=>$drive],
        'last_duration_ms'=>1234,
        'drive'=>[
            'last_success_at'=>$mode === 'offsite_success'
                ? '2026-09-30T12:01:00Z' : null,
            'last_error'=>$mode === 'offsite_secret_error'
                ? 'SYNTHETIC_SECRET_TOKEN/PRIVATE' : null,
            'remote_folder'=>'SYNTHETIC_REMOTE_FOLDER',
        ],
    ];
};
$now = static fn(): string => '2026-09-30T12:34:56+00:00';
echo json_encode(brvtalAdminBackupSignals($backups,$automation,$now),
    JSON_THROW_ON_ERROR);
"""


def run(*args: str, cwd: Path = ROOT, env: dict | None = None) -> str:
    if shutil.which(args[0]) is None:
        raise AssertionError("UNKNOWN: offline interpreter missing")
    settings = env if env is not None else {
        "PATH": os.environ.get("PATH", "/usr/bin:/bin"),
        "LANG": "C",
    }
    child = subprocess.run(
        list(args), cwd=cwd, env=settings, capture_output=True, text=True,
        check=False, timeout=35,
    )
    if child.returncode:
        raise AssertionError("UNKNOWN: offline implementation failed")
    return child.stdout


@lru_cache(maxsize=None)
def backup(mode: str) -> dict:
    if mode not in {
        "ready", "missing", "partial", "scheduler_failed", "offsite_pending",
        "offsite_failed", "offsite_success", "offsite_secret_error",
        "invalid_manifest", "invalid_automation", "source_failure",
    }:
        raise ValueError("UNKNOWN: invalid fixture")
    value = json.loads(run("php", "-d", "allow_url_fopen=0",
                           "-r", BACKUP_PHP, mode))
    if not isinstance(value, dict):
        raise AssertionError("UNKNOWN: invalid PHP projection")
    return value


@lru_cache(maxsize=None)
def dashboard(mode: str) -> dict:
    """Execute the production JS view function, not a Python translation."""
    js = (ROOT / "discadmin/dashboard-v2.js").read_text(encoding="utf-8")
    start = js.index("  function developmentBackupView(backup) {")
    end = js.index("\n\n  function developmentSources(", start)
    source = js[start:end]
    script = (
        source
        + "\nprocess.stdout.write(JSON.stringify(developmentBackupView("
        + "JSON.parse(process.argv[1]))));"
    )
    result = json.loads(run("node", "-e", script, json.dumps(backup(mode))))
    if not isinstance(result, dict):
        raise AssertionError("UNKNOWN: invalid real JS view")
    return result


def deployment(commit: str | None) -> dict:
    """Copy the actual PHP resolver into a temp tree, never use real .git."""
    with tempfile.TemporaryDirectory(prefix="brvtal-1282-deployment-") as tmp:
        root = Path(tmp)
        config = root / "config"
        config.mkdir()
        for name in ("version.php", "deployment.php"):
            shutil.copyfile(ROOT / "config" / name, config / name)
        runner = root / "runner.php"
        runner.write_text("""<?php
require __DIR__ . '/config/deployment.php';
echo json_encode(brvtalDeploymentPublicData(), JSON_THROW_ON_ERROR);
""", encoding="utf-8")
        env = {
            "PATH": os.environ.get("PATH", "/usr/bin:/bin"),
            "HOME": tmp, "LANG": "C",
        }
        if commit is not None:
            env["BRVTAL_DEPLOY_COMMIT"] = commit
        result = json.loads(run("php", "-d", "allow_url_fopen=0",
                                str(runner), cwd=root, env=env))
        if not isinstance(result, dict):
            raise AssertionError("UNKNOWN: invalid deployment observation")
        return result


class CoberturaBrvtal13Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: real Backup Health and exact deployment source, no live claims."""
        self.assertEqual(len(ALL), 20)
        self.assertEqual((len(A), len(B), len(C), len(D)), (8, 2, 10, 0))
        self.assertFalse((A & B.keys()) | (A & C.keys()) | (B.keys() & C.keys()))
        self.assertTrue(all(reason for reason in (*B.values(), *C.values())))

        ready = backup("ready")
        self.assertEqual((ready["status"], ready["freshness"]),
                         ("available", "fresh"))
        self.assertEqual(ready["backup_state"], "ready")
        self.assertEqual(ready["latest_backup"]["trigger"], "scheduled")
        self.assertEqual(ready["latest_backup"]["artifacts_bytes"], 4096)
        self.assertEqual(ready["automation"]["last_result_status"], "success")
        self.assertEqual(ready["offsite"]["status"], "disabled")
        self.assertTrue(ready["read_only"])
        self.assertEqual(dashboard("ready")["state"], "READY")

        for mode, expected in (
            ("missing", "missing"), ("partial", "partial"),
            ("scheduler_failed", "ready"), ("offsite_pending", "ready"),
            ("offsite_failed", "ready"),
        ):
            with self.subTest(backup_health=mode):
                state = backup(mode)
                self.assertEqual(state["status"], "degraded")
                self.assertEqual(state["freshness"], "degraded")
                self.assertEqual(state["backup_state"], expected)
                self.assertEqual(dashboard(mode)["freshness"], "degraded")
                self.assertEqual(dashboard(mode)["detail"], "DEGRADED")
        self.assertEqual(backup("offsite_success")["offsite"]["status"], "success")
        self.assertTrue(backup("offsite_success")["read_only"])

        # S34: independent deliberately controlled product version and exact
        # runtime deployment identity come from the actual resolver functions.
        exact = deployment(EXACT_SHA)
        self.assertEqual(exact["source"], "environment")
        self.assertTrue(exact["exact"])
        self.assertEqual(exact["commit"], EXACT_SHA)
        self.assertEqual(exact["short_commit"], EXACT_SHA[:7])
        self.assertEqual(exact["cache_key"], EXACT_SHA[:7])
        self.assertEqual(exact["release_identity"], "v" + exact["version"])
        self.assertEqual(exact["environment"], "PRODUCTION")
        fallback = deployment(None)
        self.assertEqual(fallback["source"], "release_fallback")
        self.assertFalse(fallback["exact"])
        self.assertIsNone(fallback["commit"])
        self.assertIsNone(fallback["short_commit"])
        self.assertEqual(fallback["version"], exact["version"])
        self.assertEqual(fallback["cache_key"], "release-" + exact["version"])
        self.assertEqual(fallback["release_identity"], exact["release_identity"])

    def test_falla_cerrado(self) -> None:
        """AC-02: unavailable/ambiguous sources, private errors, fake SHA."""
        for mode in ("source_failure", "invalid_manifest", "invalid_automation"):
            with self.subTest(source=mode):
                state = backup(mode)
                self.assertEqual((state["status"], state["freshness"]),
                                 ("unavailable", "unavailable"))
                self.assertIsNone(state["latest_backup"])
                self.assertEqual(dashboard(mode)["state"], "UNAVAILABLE")
        secret_error = backup("offsite_secret_error")
        self.assertEqual(secret_error["status"], "degraded")
        self.assertEqual(secret_error["offsite"]["status"], "failed")
        self.assertEqual(secret_error["offsite"]["last_error"], "OFFSITE_FAILED")
        self.assertEqual(dashboard("offsite_secret_error")["offsite"], "FAILED")

        malformed = dict(backup("ready"), read_only=False)
        source = (ROOT / "discadmin/dashboard-v2.js").read_text(encoding="utf-8")
        start = source.index("  function developmentBackupView(backup) {")
        end = source.index("\n\n  function developmentSources(", start)
        script = source[start:end] + "\nprocess.stdout.write(JSON.stringify(" \
            "developmentBackupView(JSON.parse(process.argv[1]))));"
        invalid = json.loads(run("node", "-e", script, json.dumps(malformed)))
        self.assertEqual(invalid["state"], "UNAVAILABLE")

        # No unverified env value is ever promoted to an exact Git identity.
        bad = deployment("SYNTHETIC_INVALID_SHA")
        self.assertEqual(bad["source"], "release_fallback")
        self.assertFalse(bad["exact"])
        self.assertIsNone(bad["commit"])
        # The committed admin endpoint is GET-only and authentication-gated:
        # a backup-health read cannot become a one-click restore/write.
        endpoint = (ROOT / "api/admin-backup-signals.php").read_text(
            encoding="utf-8")
        self.assertLess(endpoint.index("brvtal_admin_require();"),
                        endpoint.index("json_response("))
        self.assertIn("'METHOD_NOT_ALLOWED'", endpoint)
        self.assertNotIn("brvtal_backup_create(", endpoint)
        self.assertNotIn("brvtal_backup_cleanup(", endpoint)
        self.assertNotIn("restore", endpoint.lower())

    def test_sin_datos_reales(self) -> None:
        """AC-03: synthetic callbacks, private fields stripped, no network."""
        for mode in ("ready", "offsite_secret_error", "source_failure"):
            with self.subTest(mode=mode):
                encoded = json.dumps(backup(mode)) + json.dumps(dashboard(mode))
                for value in PRIVATE:
                    self.assertNotIn(value, encoded)
                for field in ('"sha256"', '"filename"', '"path"', '"remote_folder"'):
                    self.assertNotIn(field, encoded)
        self.assertTrue(backup("ready")["read_only"])
        self.assertIn("static function() use ($mode)", BACKUP_PHP)
        self.assertNotIn("new PDO(", BACKUP_PHP)
        self.assertNotIn("brvtal_backup_create(", BACKUP_PHP)
        self.assertNotIn("brvtal_backup_automation_run(", BACKUP_PHP)
        self.assertNotIn("curl_", BACKUP_PHP)
        self.assertNotIn("file_get_contents(", BACKUP_PHP)
        self.assertEqual(len(EXACT_SHA), 40)
        self.assertEqual(deployment(EXACT_SHA)["source"], "environment")


if __name__ == "__main__":
    unittest.main()
