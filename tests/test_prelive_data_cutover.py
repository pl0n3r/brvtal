"""Ensayo #1041 estrictamente offline; jamás se conecta a una BD externa."""
from __future__ import annotations

import hashlib
import sqlite3
import unittest
from pathlib import Path

DOC = Path(__file__).resolve().parents[1] / "docs" / "prelive-data-cutover.md"
ADDITIVE_SQL = "ALTER TABLE event_fixture ADD COLUMN release_tag TEXT"
OPTIONS = {
    "target": "sqlite-memory",
    "dataset": "synthetic-only",
    "network": False,
    "production": False,
    "deployment_requested": False,
    "credentials": None,
    "backup_ready": True,
    "migration_sql": ADDITIVE_SQL,
}
STAGES = (
    "inventory", "backup_preflight", "migration", "smoke",
    "rollback", "verify",
)


class PreflightDenied(ValueError):
    """Ninguna conexión se abre si la configuración no es segura."""


def preflight(options: dict[str, object]) -> None:
    if not isinstance(options, dict) or set(options) != set(OPTIONS):
        raise PreflightDenied("UNKNOWN_CONFIG_OR_SECRET")
    for key, expected in OPTIONS.items():
        if type(options[key]) is not type(expected) or options[key] != expected:
            raise PreflightDenied("NOT_SYNTHETIC_OR_UNVERIFIED_" + key.upper())


def fingerprint(connection: sqlite3.Connection) -> str:
    """Huella de tablas + filas de fixture, nunca SQL ni datos reales."""
    schema = connection.execute(
        "SELECT name, type, sql FROM sqlite_master "
        "WHERE name NOT LIKE 'sqlite_%' ORDER BY name"
    ).fetchall()
    rows = connection.execute(
        "SELECT id, title FROM event_fixture ORDER BY id"
    ).fetchall()
    return hashlib.sha256(repr((schema, rows)).encode("utf-8")).hexdigest()


def run_synthetic_rehearsal(
    options: dict[str, object] | None = None,
) -> dict[str, object]:
    """Solo usa tres conexiones in-memory y una allowlist SQL de un literal."""
    preflight(OPTIONS.copy() if options is None else options)
    source = sqlite3.connect(":memory:")
    snapshot = None
    restored = None
    events: list[dict[str, str]] = []

    def record(stage: str, evidence: str) -> None:
        events.append({"stage": stage, "status": "PASS", "evidence": evidence})

    try:
        source.execute(
            "CREATE TABLE event_fixture (id INTEGER PRIMARY KEY, title TEXT NOT NULL)"
        )
        source.executemany(
            "INSERT INTO event_fixture(id, title) VALUES (?, ?)",
            [(1, "SIM-EVENT-A"), (2, "SIM-EVENT-B")],
        )
        source.commit()
        initial_sha = fingerprint(source)
        record("inventory", "INVENTORY_SYNTHETIC_SHA256")

        snapshot = sqlite3.connect(":memory:")
        source.backup(snapshot)
        if fingerprint(snapshot) != initial_sha:
            raise AssertionError("BACKUP_HASH_MISMATCH")
        record("backup_preflight", "BACKUP_VERIFIED_SYNTHETIC")

        source.execute(ADDITIVE_SQL)
        source.execute(
            "UPDATE event_fixture SET release_tag=? WHERE id=?",
            ("SIM-RELEASE-V2", 1),
        )
        source.commit()
        record("migration", "MIGRATION_APPLIED_SYNTHETIC")

        columns = [row[1] for row in source.execute("PRAGMA table_info(event_fixture)")]
        count = source.execute("SELECT COUNT(*) FROM event_fixture").fetchone()[0]
        marker = source.execute(
            "SELECT release_tag FROM event_fixture WHERE id=1"
        ).fetchone()
        if "release_tag" not in columns or count != 2 or marker != ("SIM-RELEASE-V2",):
            raise AssertionError("SMOKE_FAILED")
        record("smoke", "SMOKE_PASSED_SYNTHETIC")

        restored = sqlite3.connect(":memory:")
        snapshot.backup(restored)
        record("rollback", "ROLLBACK_RESTORED_SYNTHETIC")

        final_sha = fingerprint(restored)
        restored_columns = [
            row[1] for row in restored.execute("PRAGMA table_info(event_fixture)")
        ]
        if final_sha != initial_sha or "release_tag" in restored_columns:
            raise AssertionError("ROLLBACK_INTEGRITY_FAILED")
        record("verify", "RESTORE_VERIFIED_SYNTHETIC")
        return {
            "status": "PASS",
            "environment": "sqlite-memory",
            "stages": events,
            "initial_sha256": initial_sha,
            "restored_sha256": final_sha,
        }
    finally:
        source.close()
        if snapshot is not None:
            snapshot.close()
        if restored is not None:
            restored.close()


class PreliveDataCutoverTests(unittest.TestCase):
    def test_mapping_and_synthetic_boundary(self) -> None:
        text = DOC.read_text(encoding="utf-8")
        for reference in (
            "docs/lista-salida-produccion.md",
            "docs/MIGRATIONS.md",
            "scripts/migrations.php",
            "config/migrations.php",
            "config/backups.php",
            "discadmin/backups.php",
            "schema_migrations",
            "MariaDB",
            "SQLite",
            "#1039",
            "#1044",
            "synthetic-only",
            "NO-GO",
        ):
            self.assertIn(reference, text)
        positions = [text.index("| " + str(i) + ". " + stage) for i, stage in
                     enumerate(STAGES, 1)]
        self.assertEqual(positions, sorted(positions))
        self.assertIn("No es una autorización de go-live", text)

    def test_rehearsal_terminal_backup_migrate_smoke_rollback(self) -> None:
        report = run_synthetic_rehearsal()
        self.assertEqual(report["status"], "PASS")
        self.assertEqual(report["environment"], "sqlite-memory")
        self.assertEqual([event["stage"] for event in report["stages"]], list(STAGES))
        self.assertTrue(all(event["status"] == "PASS" for event in report["stages"]))
        self.assertEqual(report["initial_sha256"], report["restored_sha256"])
        self.assertEqual(len(report["initial_sha256"]), 64)
        for marker in (
            "BACKUP_VERIFIED_SYNTHETIC",
            "MIGRATION_APPLIED_SYNTHETIC",
            "SMOKE_PASSED_SYNTHETIC",
            "ROLLBACK_RESTORED_SYNTHETIC",
            "RESTORE_VERIFIED_SYNTHETIC",
        ):
            self.assertIn(marker, [e["evidence"] for e in report["stages"]])

    def test_rejects_production_credentials_destructive_and_missing_backup(self) -> None:
        unsafe = (
            {"target": "mysql://production.example"},
            {"target": "/tmp/my.db"},
            {"dataset": "customer-import"},
            {"network": True},
            {"production": True},
            {"deployment_requested": True},
            {"credentials": "never-use-real-secret"},
            {"backup_ready": False},
            {"migration_sql": "DROP TABLE event_fixture"},
            {"migration_sql": "TRUNCATE TABLE event_fixture"},
            {"migration_sql": "DELETE FROM event_fixture"},
            {"migration_sql": "ALTER TABLE event_fixture DROP COLUMN title"},
            {"host": "production.example"},
            {"password": "fake"},
            {"dsn": "sqlite://external"},
        )
        for change in unsafe:
            with self.subTest(change=tuple(change.keys())):
                config = OPTIONS.copy()
                config.update(change)
                with self.assertRaises(PreflightDenied):
                    run_synthetic_rehearsal(config)


if __name__ == "__main__":
    unittest.main()
