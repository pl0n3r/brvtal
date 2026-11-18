#!/usr/bin/env python3
"""BRVTAL #1139: synthetic, fail-closed evidence for Backup Health gaps.

This is NOT a production health or restore-readiness attestation.
Only tests/ is changed: no credentials, network, DB or real backup.
"""
from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
BACKUP_ENDPOINT = ROOT / "discadmin/backups.php"
BACKUP_UI = ROOT / "discadmin/backups.js"
CLAUSES = ("S33-R012", "S33-R013", "S33-R014", "S33-R015", "S33-R016")
UNKNOWN = "UNKNOWN"

PHP_READ_ONLY = r"""
require 'config/backups.php';
require 'config/backup_automation.php';
$dir = $argv[1];
$items = brvtal_backup_list($dir);
$state = brvtal_backup_automation_public_state(brvtal_backup_automation_read($dir));
echo json_encode(['items'=>$items, 'automation'=>$state], JSON_THROW_ON_ERROR);
"""


def matrix_rows(source: str) -> dict[str, list[str]]:
    """Ambiguous or missing matrix rows never become accepted evidence."""
    found: dict[str, list[str]] = {}
    for line in source.splitlines():
        match = re.match(r"^\| (S33-R01[2-6]) ·", line)
        if match is None:
            continue
        key = match.group(1)
        if key in found:
            return {}
        cells = [cell.strip() for cell in line.strip("|").split("|")]
        if len(cells) != 5:
            return {}
        found[key] = cells
    return found


def evidence_state(observation: object, *, complete: bool = False) -> str:
    """Local evidence is NOT an integrated Backup Health signal."""
    return "TEST_EXECUTED" if complete and observation is True else UNKNOWN


def isolated_env(home: str) -> dict[str, str]:
    return {"PATH": os.environ.get("PATH", "/usr/bin:/bin"),
            "HOME": home, "LANG": "C", "APP_ENV": "testing"}


def php_inventory_fixture(*, partial: bool, scheduler_failed: bool) -> dict:
    """Observe real PHP readers using only disposable local fixture files."""
    executable = shutil.which("php")
    if executable is None:
        return {"unavailable": True}
    with tempfile.TemporaryDirectory(prefix="brvtal-backup-health-synthetic-") as temp:
        directory = Path(temp)
        manifest = {
            "id": "brvtal-20261028T120000Z-abcdef12",
            "created_at": "2026-10-28T12:00:00+00:00",
            "status": "partial" if partial else "ready",
            "scope": "database", "trigger": "scheduled",
            "components": {"database": {
                "status": "unavailable" if partial else "ready",
                "file": "synthetic-private.sql", "sha256": "synthetic-checksum"
            }},
            "restore_supported": False,
        }
        manifest_file = directory / (manifest["id"] + ".manifest.json")
        manifest_file.write_text(json.dumps(manifest), encoding="utf-8")
        scheduler_dir = directory / ".automation"
        scheduler_dir.mkdir()
        scheduler = {
            "config": {"enabled": True, "scope": "database",
                       "drive": {"enabled": True, "folder": "synthetic-private-folder"}},
            "last_run_at": "2026-10-28T12:01:00+00:00",
            "last_success_at": None if scheduler_failed else "2026-10-28T12:01:00+00:00",
            "last_result": {"status": "failed" if scheduler_failed else "success",
                            "local": "failed" if scheduler_failed else "success",
                            "drive": "failed" if scheduler_failed else "success"},
            "drive": {"last_success_at": None if scheduler_failed else "2026-10-28T12:01:00+00:00",
                      "last_error": "SYNTHETIC_DELIVERY_FAILED" if scheduler_failed else None},
        }
        scheduler_file = scheduler_dir / "state.json"
        scheduler_file.write_text(json.dumps(scheduler), encoding="utf-8")
        before = {p: p.read_bytes() for p in (manifest_file, scheduler_file)}
        result = subprocess.run(
            [executable, "-r", PHP_READ_ONLY, str(directory)], cwd=ROOT,
            env=isolated_env(temp), capture_output=True, text=True,
            check=False, timeout=20,
        )
        if result.returncode:
            raise AssertionError("Offline PHP reader failed: " + result.stderr[-1000:])
        after = {p: p.read_bytes() for p in before}
        if before != after:
            raise AssertionError("Read-only readers changed backup/scheduler evidence")
        return json.loads(result.stdout)


class BrechasBrvtal49Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """Five rows, each witnessed offline or explicitly unverified."""
        rows = matrix_rows(MATRIX.read_text(encoding="utf-8"))
        self.assertEqual(set(CLAUSES), set(rows))
        actual = php_inventory_fixture(partial=True, scheduler_failed=True)
        endpoint = BACKUP_ENDPOINT.read_text(encoding="utf-8")
        ui = BACKUP_UI.read_text(encoding="utf-8")
        if actual.get("unavailable"):
            self.assertEqual(UNKNOWN, evidence_state(None))
            self.skipTest("PHP CLI unavailable; no successful Backup Health claim")
        items, auto = actual["items"], actual["automation"]
        observed = {
            # Canonical local sources do not prove a separate integrated health signal.
            "S33-R012": len(items) == 1 and items[0]["status"] == "partial"
                and auto["last_result"]["status"] == "failed",
            # Readers are side-effect-free, but offsite evidence is not certified.
            "S33-R013": auto["config"]["enabled"] is True
                and auto["drive"]["last_success_at"] is None,
            # Partial backups and missing offsite success cannot imply restore readiness.
            "S33-R014": items[0]["status"] == "partial"
                and auto["last_result"]["drive"] == "failed"
                and items[0]["restore_supported"] is False,
            # The underlying manifest has private metadata; no sanitized signal proved.
            "S33-R015": "sha256" in items[0]["components"]["database"]
                and "backup_health" not in actual,
            # Actual endpoint explicitly refuses restore; no one-click restore.
            "S33-R016": "if ($action === 'restore') {" in endpoint
                and "'RESTORE_NOT_SUPPORTED'" in endpoint
                and "'restore' => false" in endpoint
                and "NO AUTOMATIC RESTORE" in ui,
        }
        self.assertEqual(set(CLAUSES), set(observed))
        for requirement, witness in observed.items():
            with self.subTest(requirement=requirement):
                self.assertTrue(witness, requirement)
                self.assertEqual("NO_VERIFICABLE", rows[requirement][2])
                self.assertEqual(UNKNOWN, evidence_state(witness, complete=False))

    def test_falla_cerrado(self) -> None:
        """Partial, missing or ambiguous evidence never equals success."""
        for missing in (None, False, {}, [], "ready", {"status": "ready"}):
            with self.subTest(missing=repr(missing)):
                self.assertEqual(UNKNOWN, evidence_state(missing))
        self.assertEqual(UNKNOWN, evidence_state(True, complete=False))
        self.assertEqual(UNKNOWN, evidence_state(None, complete=True))
        self.assertEqual({}, matrix_rows(""))
        self.assertEqual({}, matrix_rows(
            "| S33-R012 · a | text | NO_VERIFICABLE | gap | — |\n"
            "| S33-R012 · b | text | CUBIERTO | claim | — |"
        ))
        self.assertEqual({}, matrix_rows("| S33-R012 · incomplete |"))

    def test_sin_datos_reales(self) -> None:
        """No database, external I/O, inherited credentials, or real backups."""
        source = Path(__file__).read_text(encoding="utf-8")
        self.assertIn("tempfile.TemporaryDirectory(", source)
        self.assertIn("SYNTHETIC_DELIVERY_FAILED", source)
        for forbidden in ("brvtal_backup_create(", "new PDO(", "curl", "http"):
            self.assertNotIn(forbidden, PHP_READ_ONLY)
        env = isolated_env("/tmp/brvtal-synthetic")
        for secret in ("DB_PASSWORD", "DATABASE_URL", "GITHUB_TOKEN", "APP_KEY",
                       "HTTP_PROXY", "AWS_SECRET_ACCESS_KEY", "MYSQL_PWD"):
            with self.subTest(secret=secret):
                self.assertNotIn(secret, env)

    def test_estados_unknown_explicitos(self) -> None:
        """Never promote NO_VERIFICABLE to production-validated readiness."""
        rows = matrix_rows(MATRIX.read_text(encoding="utf-8"))
        self.assertEqual(set(CLAUSES), set(rows))
        for requirement in CLAUSES:
            with self.subTest(requirement=requirement):
                self.assertEqual("NO_VERIFICABLE", rows[requirement][2])
                self.assertIn("Sin aserción integral confirmada", rows[requirement][3])
                self.assertEqual(UNKNOWN, evidence_state(True, complete=False))
                self.assertNotIn("VALIDATED_IN_PRODUCTION", rows[requirement][3])


if __name__ == "__main__":
    unittest.main()
