"""BRVTAL #1039: isolated, synthetic-only backup/restore rehearsal.

This does not read Hostinger, browser profiles, credentials, user media, or production DB.
"""
from __future__ import annotations

import hashlib
import re
import sqlite3
import time
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "prelive-restore-rehearsal.md"
RPO_OBJECTIVE_SECONDS = 900  # illustrative lab budget, not a production SLA
RTO_OBJECTIVE_SECONDS = 3600
CONFIG = {
    "mode": "sqlite-memory",
    "dataset": "fictional-only",
    "network": False,
    "production": False,
    "hostinger": False,
    "credentials": None,
    "restore_supported": False,
}
EXPECTED_FILES = frozenset(("database.sql", "media/fake-banner.txt"))
SENSITIVE = re.compile(rb"bearer\s|authorization\s*:|password|secret|token|cookie|chat[ _-]?content|[\w.-]+@[\w.-]+\.[A-Za-z]{2,}", re.I)


class RehearsalDenied(ValueError):
    """Unsafe inputs always fail before any restore."""


def preflight(config: dict[str, object]) -> None:
    if not isinstance(config, dict) or set(config) != set(CONFIG):
        raise RehearsalDenied("UNKNOWN_OPTIONS")
    for key, expected in CONFIG.items():
        if type(config[key]) is not type(expected) or config[key] != expected:
            raise RehearsalDenied("NOT_SYNTHETIC_" + key.upper())


def sha256(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def database_dump(db: sqlite3.Connection) -> bytes:
    return ("\n".join(db.iterdump()) + "\n").encode("utf-8")


def build_manifest(payloads: dict[str, bytes]) -> dict[str, object]:
    if not isinstance(payloads, dict) or set(payloads) != EXPECTED_FILES:
        raise RehearsalDenied("MISSING_OR_UNEXPECTED_COMPONENT")
    components = {}
    for name in sorted(EXPECTED_FILES):
        data = payloads[name]
        if type(data) is not bytes or not data or SENSITIVE.search(data):
            raise RehearsalDenied("UNSAFE_OR_EMPTY_COMPONENT")
        components[name] = {"bytes": len(data), "sha256": sha256(data)}
    return {"schema": 1, "synthetic_only": True, "components": components}


def verify_manifest(manifest: dict[str, object], payloads: dict[str, bytes]) -> None:
    if not isinstance(manifest, dict) or set(manifest) != {"schema", "synthetic_only", "components"}:
        raise RehearsalDenied("MISSING_OR_MALFORMED_EVIDENCE")
    if type(manifest["schema"]) is not int or manifest["schema"] != 1 or manifest["synthetic_only"] is not True:
        raise RehearsalDenied("NOT_SYNTHETIC_EVIDENCE")
    if build_manifest(payloads) != manifest:
        raise RehearsalDenied("INTEGRITY_MISMATCH")


def fingerprint(db: sqlite3.Connection) -> str:
    return sha256(database_dump(db))


def rehearse(config: dict[str, object] | None = None) -> dict[str, object]:
    preflight(CONFIG.copy() if config is None else config)
    source = sqlite3.connect(":memory:")
    snapshot = sqlite3.connect(":memory:")
    restored = sqlite3.connect(":memory:")
    audit: list[dict[str, str]] = []
    try:
        source.execute("CREATE TABLE event_fixture (id INTEGER PRIMARY KEY, label TEXT NOT NULL)")
        source.executemany("INSERT INTO event_fixture VALUES (?, ?)", ((1, "demo-one"), (2, "demo-two")))
        source.commit()
        captured_at = time.monotonic()
        expected_sha = fingerprint(source)
        source.backup(snapshot)
        payloads = {
            "database.sql": database_dump(snapshot),
            "media/fake-banner.txt": b"FAKE-MEDIA-NONPRODUCTION\n",
        }
        manifest = build_manifest(payloads)
        verify_manifest(manifest, payloads)
        audit.append({"stage": "backup", "result": "PASS", "sha256": expected_sha})
        source.execute("INSERT INTO event_fixture VALUES (3, 'after-backup')")
        source.commit()
        restore_start = time.monotonic()
        snapshot.backup(restored)
        actual_sha = fingerprint(restored)
        recovered_rows = restored.execute("SELECT id, label FROM event_fixture ORDER BY id").fetchall()
        if actual_sha != expected_sha or len(recovered_rows) != 2 or any(row[1] == "after-backup" for row in recovered_rows):
            raise RehearsalDenied("RESTORE_CONTENT_MISMATCH")
        audit.append({"stage": "restore", "result": "PASS", "sha256": actual_sha})
        rpo = max(0.0, restore_start - captured_at)
        rto = max(0.0, time.monotonic() - restore_start)
        if rpo > RPO_OBJECTIVE_SECONDS or rto > RTO_OBJECTIVE_SECONDS:
            raise RehearsalDenied("ILLUSTRATIVE_BUDGET_EXCEEDED")
        audit.append({"stage": "rollback_verify", "result": "PASS", "sha256": actual_sha})
        return {
            "environment": "sqlite-memory+fake-media",
            "synthetic_only": True,
            "production_restore": "DISABLED",
            "backup_sha256": expected_sha,
            "restored_sha256": actual_sha,
            "manifest": manifest,
            "audit": audit,
            "rpo_objective_seconds": RPO_OBJECTIVE_SECONDS,
            "rto_objective_seconds": RTO_OBJECTIVE_SECONDS,
            "rpo_within_objective": rpo <= RPO_OBJECTIVE_SECONDS,
            "rto_within_objective": rto <= RTO_OBJECTIVE_SECONDS,
            "status": "PASS",
        }
    finally:
        restored.close()
        snapshot.close()
        source.close()


class PreliveRestoreRehearsalTests(unittest.TestCase):
    def test_manifest_rpo_rto_checksums(self) -> None:
        report = rehearse()
        self.assertEqual(report["status"], "PASS")
        self.assertTrue(report["synthetic_only"])
        self.assertEqual(report["production_restore"], "DISABLED")
        self.assertEqual(report["backup_sha256"], report["restored_sha256"])
        self.assertEqual(len(report["backup_sha256"]), 64)
        self.assertEqual(set(report["manifest"]["components"]), EXPECTED_FILES)
        self.assertTrue(report["rpo_within_objective"])
        self.assertTrue(report["rto_within_objective"])

    def test_isolated_restoration_excludes_post_backup_mutation(self) -> None:
        report = rehearse()
        self.assertEqual([step["stage"] for step in report["audit"]],
                         ["backup", "restore", "rollback_verify"])
        self.assertTrue(all(step["result"] == "PASS" for step in report["audit"]))
        self.assertTrue(all(step["sha256"] == report["backup_sha256"] for step in report["audit"]))
        self.assertEqual(report["backup_sha256"], rehearse()["backup_sha256"])

    def test_missing_tampered_sensitive_and_live_rejected(self) -> None:
        for overrides in ({"network": True}, {"production": True}, {"hostinger": True},
                          {"credentials": "fake"}, {"dataset": "real"},
                          {"restore_supported": True}, {"mode": "external"},
                          {"unexpected_option": False}):
            with self.subTest(overrides=overrides):
                with self.assertRaises(RehearsalDenied):
                    rehearse({**CONFIG, **overrides})
        payloads = {"database.sql": b"CREATE TABLE synthetic (id INTEGER);\n",
                    "media/fake-banner.txt": b"FAKE-MEDIA-NONPRODUCTION\n"}
        manifest = build_manifest(payloads)
        for wrong in (None, {}, {**manifest, "schema": 2},
                      {**manifest, "synthetic_only": False},
                      {**manifest, "components": {}}):
            with self.subTest(wrong=wrong):
                with self.assertRaises(RehearsalDenied):
                    verify_manifest(wrong, payloads)
        tampered = {**payloads, "media/fake-banner.txt": b"CORRUPTED\n"}
        with self.assertRaises(RehearsalDenied):
            verify_manifest(manifest, tampered)
        for unsafe in ({**payloads, "database.sql": b"Bearer synthetic-secret"},
                       {**payloads, "media/fake-banner.txt": b"user@example.com"},
                       {**payloads, "../../private.ini": b"x"}):
            with self.assertRaises(RehearsalDenied):
                build_manifest(unsafe)

    def test_runbook_explicit_nonproduction_boundaries(self) -> None:
        content = DOC.read_text(encoding="utf-8")
        for required in ("#1039", "RPO", "RTO", "SHA-256", "PASS", "FAIL",
                         "restauración", "reversión", "Hostinger", "sintético",
                         "credenciales", "PRODUCCIÓN NO VALIDADA", "restore_supported"):
            with self.subTest(required=required):
                self.assertIn(required, content)
        self.assertIn("python3 -m unittest -v tests.test_prelive_restore_rehearsal", content)
        self.assertIn("tests/integration/backup-recovery-rehearsal.php", content)


if __name__ == "__main__":
    unittest.main()
