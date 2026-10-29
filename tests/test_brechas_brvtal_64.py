#!/usr/bin/env python3
"""#1154: S40-R009..R013: offline audit/migration contracts and explicit UNKNOWN.

Committed schema and pure migration guards are testable; past production
migrations and live TOTP keys are not observable in an offline CI checkout.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(f"S40-R{i:03d}" for i in range(9, 14))
LABELS = {
    "S40-R009": "admin_activity_log.",
    "S40-R010": "This list may evolve.",
    "S40-R011": "Production migrations already applied include the Content Core, Releases, Blog, SEO and Admin Activity foundations.",
    "S40-R012": "Do not re-run previously applied production migrations blindly.",
    "S40-R013": "TOTP compatibility/key work reused existing schema/settings where possible and did not require a new production migration for the persistent encryption key.",
}
SPEC_CLAIMS = dict(LABELS)
SPEC_CLAIMS["S40-R009"] = "- " + chr(96) + "admin_activity_log" + chr(96) + "."
FILES = {
    "matrix": "docs/matriz-cobertura.md",
    "spec": "docs/BRVTAL-SPEC.md",
    "audit": "database/migration_admin_activity_01.sql",
    "content": "database/migration_content_core_01.sql",
    "releases": "database/migration_releases_01.sql",
    "blog": "database/migration_blog_01.sql",
    "seo": "database/migration_seo_01.sql",
    "registry": "database/migration_schema_migrations_01.sql",
    "library": "config/migrations.php",
    "reconcile": "config/migration_reconcile.php",
    "cli": "scripts/migrations.php",
    "totp": "config/totp.php",
    "totp_schema": "database/migration_totp_foundation.sql",
}
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_PARTIAL"
OPERATIONAL_GAPS = {
    "S40-R010": "An open-ended table list cannot certify future schemas.",
    "S40-R011": "Committed SQL is not evidence of applied production migrations.",
    "S40-R013": "TOTP key compatibility in source does not certify live key/schema history.",
}

# Require only pure migration functions: no PDO, database, network or bootstrap.
PHP_PROBE = r"""
require $argv[1];
function inspect_status(array $status, string $expected): string {
    try {
        brvtalMigrationVerifyPlanStatus($status, $expected);
        return 'OK';
    } catch (Throwable $error) {
        return $error->getMessage();
    }
}
$applied = ['migration' => 'migration_existing_01.sql', 'state' => 'applied'];
$pending = ['migration' => 'migration_new_01.sql', 'state' => 'pending'];
$extra = ['migration' => 'migration_other_01.sql', 'state' => 'pending'];
$base = ['registry_exists' => true, 'migrations' => [$applied], 'orphaned_records' => []];
$result = [
    'no_op_applied' => inspect_status($base, '__NONE__'),
    'no_op_pending' => inspect_status(
        ['registry_exists' => true, 'migrations' => [$applied, $pending]], '__NONE__'),
    'missing_registry' => inspect_status(
        ['registry_exists' => false, 'migrations' => [$applied]], '__NONE__'),
    'orphaned_record' => inspect_status(
        ['registry_exists' => true, 'migrations' => [$applied],
         'orphaned_records' => [['migration' => 'migration_lost_01.sql']]], '__NONE__'),
    'checksum_drift' => inspect_status(
        ['registry_exists' => true, 'migrations' => [
            ['migration' => 'migration_existing_01.sql', 'state' => 'checksum_mismatch']
        ]], '__NONE__'),
    'one_target_pending' => inspect_status(
        ['registry_exists' => true, 'migrations' => [$applied, $pending]],
        'migration_new_01.sql'),
    'two_targets_pending' => inspect_status(
        ['registry_exists' => true, 'migrations' => [$pending, $extra]],
        'migration_new_01.sql'),
];
try {
    brvtalMigrationAssertAdditiveSql('CREATE TABLE IF NOT EXISTS synthetic_probe (id INT);');
    $result['additive_ddl'] = 'OK';
} catch (Throwable $error) {
    $result['additive_ddl'] = $error->getMessage();
}
try {
    brvtalMigrationAssertAdditiveSql('DROP TABLE synthetic_probe;');
    $result['destructive_ddl'] = 'UNSAFE_PASS';
} catch (Throwable $error) {
    $result['destructive_ddl'] = $error->getMessage();
}
echo json_encode($result, JSON_THROW_ON_ERROR);
"""
EXPECTED = {
    "no_op_applied": "OK",
    "no_op_pending": "MIGRATION_PLAN_DB_DRIFT",
    "missing_registry": "MIGRATION_REGISTRY_MISSING",
    "orphaned_record": "MIGRATION_PLAN_DB_DRIFT",
    "checksum_drift": "MIGRATION_CHECKSUM_MISMATCH",
    "one_target_pending": "OK",
    "two_targets_pending": "MIGRATION_PLAN_DB_DRIFT",
    "additive_ddl": "OK",
    "destructive_ddl": "MIGRATION_NON_ADDITIVE_SQL",
}


def rows(raw: object) -> dict[str, tuple[str, ...]]:
    """Require five unchanged NO_VERIFICABLE rows; duplicate/missing = UNKNOWN."""
    if type(raw) is not str or not raw:
        raise ValueError(UNKNOWN + ": missing matrix")
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        match = re.match(r"^\| (S40-R0(?:09|10|11|12|13)) · ", line)
        if not match:
            continue
        key = match.group(1)
        cells = tuple(cell.strip() for cell in line.strip("|").split("|"))
        if (key in found or len(cells) != 5 or cells[1] != LABELS[key]
                or cells[2] != "NO_VERIFICABLE"
                or "Sin aserción contrastada" not in cells[3]
                or cells[4] != "—"):
            raise ValueError(UNKNOWN + ": ambiguous " + key)
        found[key] = cells
    if set(found) != set(IDS):
        raise ValueError(UNKNOWN + ": incomplete matrix contract")
    return found


def sources() -> dict[str, str]:
    return {key: (ROOT / path).read_text(encoding="utf-8")
            for key, path in FILES.items()}


def isolated_env(home: str) -> dict[str, str]:
    return {"PATH": os.defpath, "HOME": home, "LANG": "C", "APP_ENV": "testing"}


def probe() -> dict[str, str]:
    if shutil.which("php") is None:
        raise AssertionError(UNKNOWN + ": PHP CLI unavailable")
    with tempfile.TemporaryDirectory(prefix="brvtal-s40-64-") as home:
        run = subprocess.run(
            ["php", "-r", PHP_PROBE, str(ROOT / FILES["library"])],
            cwd=ROOT, env=isolated_env(home), capture_output=True,
            text=True, timeout=15, check=False,
        )
    if run.returncode or run.stderr:
        raise AssertionError(UNKNOWN + ": migration guard PHP probe failed")
    observed = json.loads(run.stdout)
    if type(observed) is not dict or set(observed) != set(EXPECTED):
        raise ValueError(UNKNOWN + ": incomplete migration guard probe")
    return observed


def structural(content: dict[str, str]) -> dict[str, bool]:
    audit = content["audit"]
    return {
        "S40-R009": (
            len(re.findall(r"(?m)^CREATE TABLE IF NOT EXISTS admin_activity_log \(", audit)) == 1
            and "id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY" in audit
            and "admin_id INT UNSIGNED NULL" in audit
            and "action VARCHAR(40) NOT NULL" in audit
            and "resource VARCHAR(40) NOT NULL" in audit
            and "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP" in audit
            and all(f"INDEX {name} (" in audit for name in (
                "idx_admin_activity_created", "idx_admin_activity_resource",
                "idx_admin_activity_admin"))
            and "FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL" in audit
            and "'migration_admin_activity_01.sql' => brvtalMigrationObjectRequirements(" in content["reconcile"]
            and "'admin_activity_log'" in content["reconcile"]
        ),
        "S40-R010": "## 40. Current database model" in content["spec"],
        "S40-R011": all(needle in content[name] for name, needle in (
            ("content", "CREATE TABLE IF NOT EXISTS event_ticket_types"),
            ("releases", "CREATE TABLE IF NOT EXISTS releases"),
            ("blog", "CREATE TABLE IF NOT EXISTS blog_posts"),
            ("seo", "ADD COLUMN IF NOT EXISTS seo_title"),
            ("audit", "CREATE TABLE IF NOT EXISTS admin_activity_log"),
        )),
        "S40-R012": (
            "function brvtalMigrationVerifyPlanStatus" in content["library"]
            and "function brvtalMigrationAssertAdditiveSql" in content["library"]
            and "MIGRATION_REGISTRY_MISSING" in content["library"]
            and "MIGRATION_CHECKSUM_MISMATCH" in content["library"]
            and "checksum_sha256 CHAR(64) NOT NULL" in content["registry"]
            and "BRVTAL_MIGRATIONS_ALLOW_WRITE" in content["cli"]
            and "--confirm" in content["cli"]
            and "if ($command === 'verify-plan')" in content["cli"]
            and "if ($command === 'apply')" in content["cli"]
            and "apply-all" not in content["cli"]
        ),
        "S40-R013": (
            "function brvtal_totp_encryption_key()" in content["totp"]
            and "function brvtal_totp_database_key(bool $create)" in content["totp"]
            and "security.totp_encryption_key" in content["totp"]
            and "SELECT setting_value FROM settings" in content["totp"]
            and "INSERT INTO settings(setting_key,setting_value,is_json)" in content["totp"]
            and "ADD COLUMN totp_secret_enc TEXT NULL" in content["totp_schema"]
        ),
    }


def evidence(key: object, content: object, observed: object) -> str:
    """Offline evidence is not proof of live deployment/migration."""
    if (type(key) is not str or key not in IDS or type(content) is not dict
            or set(content) != set(FILES) or type(observed) is not dict
            or any(type(content[name]) is not str or not content[name] for name in FILES)
            or observed != EXPECTED):
        return UNKNOWN
    try:
        rows(content["matrix"])
    except ValueError:
        return UNKNOWN
    if ("## 40. Current database model" not in content["spec"]
            or any(claim not in content["spec"] for claim in SPEC_CLAIMS.values())
            or not structural(content)[key]):
        return UNKNOWN
    return LOCAL if key in {"S40-R009", "S40-R012"} else UNKNOWN


def gap(key: object, content: object, observed: object) -> str:
    if key not in OPERATIONAL_GAPS:
        return UNKNOWN
    if (type(content) is not dict or set(content) != set(FILES)
            or type(observed) is not dict or observed != EXPECTED):
        return UNKNOWN
    try:
        rows(content["matrix"])
    except ValueError:
        return UNKNOWN
    if (not all(SPEC_CLAIMS[k] in content["spec"] for k in IDS)
            or not structural(content)[key]):
        return UNKNOWN
    return f"{key}: UNKNOWN — {OPERATIONAL_GAPS[key]}"


def operational(_key: object, _claim: object = None) -> str:
    return UNKNOWN


class BrechasBrvtal64Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.content = sources()
        cls.observed = probe()

    def test_contrato_principal(self) -> None:
        self.assertEqual(set(rows(self.content["matrix"])), set(IDS))
        self.assertEqual(set(LABELS), set(IDS))
        self.assertEqual(self.observed, EXPECTED)
        checks = structural(self.content)
        self.assertEqual(set(checks), set(IDS))
        for key in IDS:
            with self.subTest(row=key):
                self.assertTrue(checks[key], "missing source safeguard: " + key)
                self.assertEqual(rows(self.content["matrix"])[key][2], "NO_VERIFICABLE")
                self.assertEqual(evidence(key, self.content, self.observed),
                                 LOCAL if key in {"S40-R009", "S40-R012"} else UNKNOWN)
                if key in OPERATIONAL_GAPS:
                    self.assertTrue(gap(key, self.content, self.observed).startswith(
                        key + ": UNKNOWN"))

    def test_falla_cerrado(self) -> None:
        raw = self.content["matrix"]
        for key in IDS:
            original = next(line for line in raw.splitlines()
                            if line.startswith("| " + key + " · "))
            for damaged in (raw.replace(original, "", 1), raw + "\n" + original,
                            raw.replace(original, "| " + key + " · malformed |", 1)):
                with self.subTest(key=key, size=len(damaged)):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        rows(damaged)
                    self.assertEqual(evidence(key, {**self.content, "matrix": damaged},
                                              self.observed), UNKNOWN)
        for name in FILES:
            truncated = dict(self.content)
            truncated.pop(name)
            self.assertTrue(all(evidence(k, truncated, self.observed) == UNKNOWN
                                for k in IDS))
        self.assertEqual(evidence("S40-R099", self.content, self.observed), UNKNOWN)
        self.assertEqual(evidence(IDS[3], self.content,
                                  {"no_op_applied": "OK"}), UNKNOWN)
        tampered_audit = dict(self.content)
        tampered_audit["audit"] = tampered_audit["audit"].replace(
            "ON DELETE SET NULL", "ON DELETE CASCADE")
        self.assertEqual(evidence("S40-R009", tampered_audit, self.observed), UNKNOWN)
        tampered_cli = dict(self.content)
        tampered_cli["cli"] = tampered_cli["cli"].replace(
            "BRVTAL_MIGRATIONS_ALLOW_WRITE", "UNGUARDED_WRITE")
        self.assertEqual(evidence("S40-R012", tampered_cli, self.observed), UNKNOWN)
        tampered_totp = dict(self.content)
        tampered_totp["totp"] = tampered_totp["totp"].replace(
            "security.totp_encryption_key", "untracked_key")
        self.assertEqual(gap("S40-R013", tampered_totp, self.observed), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        self.assertTrue(all((ROOT / p).is_file() for p in FILES.values()))
        with tempfile.TemporaryDirectory(prefix="brvtal-s40-64-safe-") as home:
            env = isolated_env(home)
            self.assertEqual(env["APP_ENV"], "testing")
            for name in ("DB_PASSWORD", "DATABASE_URL", "MYSQL_PWD",
                         "GH_TOKEN", "GITHUB_TOKEN", "APP_KEY",
                         "HTTP_PROXY", "HTTPS_PROXY"):
                self.assertNotIn(name, env)
        for prohibited in ("PDO(", "curl", "http://", "https://", "file_get_contents(",
                           "brvtal_migration_apply_file(", "db()"):
            self.assertNotIn(prohibited, PHP_PROBE)
        self.assertIn("synthetic_probe", PHP_PROBE)

    def test_estados_unknown_explicitos(self) -> None:
        for key in IDS:
            with self.subTest(row=key):
                for untrusted in (None, True, "success", {"ci": "success"},
                                  {"production": "GREEN"},
                                  {"migration": "applied"}, {"key": "configured"}):
                    self.assertEqual(operational(key, untrusted), UNKNOWN)
        for key in OPERATIONAL_GAPS:
            self.assertIn("UNKNOWN", gap(key, self.content, self.observed))
        self.assertEqual(gap("S40-R012", self.content, self.observed), UNKNOWN)
        self.assertEqual(gap("S40-R011", {**self.content, "seo": ""},
                             self.observed), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
