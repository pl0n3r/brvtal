#!/usr/bin/env python3
"""BRVTAL #1158: cinco brechas S42-R010..S43-R003, prueba offline.

Solo demuestra contratos locales ejecutados con entradas sintéticas. Nunca
certifica ausencia global de secretos, esquema de producción, despliegue,
migración aplicada, credenciales de operador ni aprobación de go-live.
"""
from __future__ import annotations

import ast
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/BRVTAL-SPEC.md"
MIGRATIONS = "config/migrations.php"
RATE = "config/password_rate_limit.php"
REGISTRY = "database/migration_schema_migrations_01.sql"
MIGRATION_TEST = "tests/migrations-contract.php"
AUTH_TEST = "tests/auth-rate-limit-contract.php"
FILES = (MATRIX, SPEC, MIGRATIONS, RATE, REGISTRY, MIGRATION_TEST, AUTH_TEST)
IDS = ("S42-R010", "S42-R011", "S43-R001", "S43-R002", "S43-R003")
CLAUSES = {
    "S42-R010": "untrusted input handling.",
    "S42-R011": "Never commit real DB passwords, API secrets, encryption keys or private credentials to GitHub.",
    "S43-R001": "Future migrations should be explicit, reviewable, data-safe, documented and idempotent where practical.",
    "S43-R002": "Do not silently mutate production schemas.",
    "S43-R003": "Source deployment and database migration are independent states.",
}
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_SYNTHETIC_PARTIAL"
GAP = "BRECHA_DOCUMENTADA"

# No PDO, filesystem writes, production connection or host/environment secrets:
# execute the real committed pure functions, with synthetic identifiers only.
PHP_PROBE = r"""
require $argv[1];
require $argv[2];
function check_offline(callable $fn): string {
    try { $fn(); return 'OK'; }
    catch (Throwable $e) { return $e->getMessage(); }
}
$pending = [
    'registry_exists' => true,
    'migrations' => [['migration'=>'migration_synthetic_01.sql','state'=>'pending']],
    'orphaned_records' => [],
];
$badChecksum = $pending;
$badChecksum['migrations'][0]['state'] = 'checksum_mismatch';
$absentRegistry = $pending;
$absentRegistry['registry_exists'] = false;
$email = '../SYNTHETIC@example.test';
$path = brvtal_password_rate_limit_path($email, '/tmp/brvtal-test-offline', '../ip');
$lower = brvtal_password_rate_limit_path(strtolower($email), '/tmp/brvtal-test-offline', '../ip');
echo json_encode([
    'safe_ddl' => check_offline(fn() => brvtalMigrationAssertAdditiveSql(
        'CREATE TABLE IF NOT EXISTS synthetic_probe (id INT);')),
    'commented_ddl' => check_offline(fn() => brvtalMigrationAssertAdditiveSql(
        "-- DROP TABLE ignored\nCREATE TABLE IF NOT EXISTS synthetic_probe (id INT);")),
    'destructive_ddl' => check_offline(fn() => brvtalMigrationAssertAdditiveSql(
        'DROP TABLE synthetic_probe;')),
    'pending_expected' => check_offline(fn() => brvtalMigrationVerifyPlanStatus(
        $pending, 'migration_synthetic_01.sql')),
    'pending_unexpected' => check_offline(fn() => brvtalMigrationVerifyPlanStatus(
        $pending, '__NONE__')),
    'registry_missing' => check_offline(fn() => brvtalMigrationVerifyPlanStatus(
        $absentRegistry, '__NONE__')),
    'checksum_mismatch' => check_offline(fn() => brvtalMigrationVerifyPlanStatus(
        $badChecksum, '__NONE__')),
    'safe_key' => preg_match('#^/tmp/brvtal-test-offline/[a-f0-9]{64}\.json$#', $path) === 1,
    'casefold' => $path === $lower,
], JSON_THROW_ON_ERROR);
"""
EXPECTED = {
    "safe_ddl": "OK",
    "commented_ddl": "OK",
    "destructive_ddl": "MIGRATION_NON_ADDITIVE_SQL",
    "pending_expected": "OK",
    "pending_unexpected": "MIGRATION_PLAN_DB_DRIFT",
    "registry_missing": "MIGRATION_REGISTRY_MISSING",
    "checksum_mismatch": "MIGRATION_CHECKSUM_MISMATCH",
    "safe_key": True,
    "casefold": True,
}
LOCAL_ROWS = {"S42-R010", "S43-R001", "S43-R003"}
GAP_ROWS = set(IDS) - LOCAL_ROWS


def rows(document: object) -> dict[str, tuple[str, ...]]:
    """Require all five unique original rows, not self-asserted new coverage."""
    if type(document) is not str or not document:
        raise ValueError(UNKNOWN + ": missing matrix")
    found: dict[str, tuple[str, ...]] = {}
    for line in document.splitlines():
        match = re.match(r"^\| (S(?:42-R01[01]|43-R00[1-3])) · ", line)
        if match is None:
            continue
        key = match.group(1)
        cells = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
        if (key in found or len(cells) != 5
                or not cells[0].startswith(key + " · [S")
                or cells[1] != CLAUSES[key]
                or cells[2] != "NO_VERIFICABLE"
                or "Sin aserción contrastada" not in cells[3]
                or "falta enlazar prueba de ese inciso, archivo de código y PR fusionado" not in cells[3]
                or cells[4] != "—"):
            raise ValueError(UNKNOWN + ": invalid or duplicated " + key)
        found[key] = cells
    if set(found) != set(IDS):
        raise ValueError(UNKNOWN + ": incomplete matrix evidence")
    return found


def sources() -> dict[str, str]:
    """Read precisely the seven committed inputs, never scan credentials."""
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in FILES}


def isolated_env() -> dict[str, str]:
    return {"PATH": os.defpath, "HOME": "/nonexistent", "LANG": "C",
            "LC_ALL": "C", "APP_ENV": "testing"}


def observe_php() -> dict[str, object]:
    php = shutil.which("php")
    if php is None:
        raise AssertionError(UNKNOWN + ": php unavailable")
    result = subprocess.run(
        [php, "-r", PHP_PROBE, str(ROOT / MIGRATIONS), str(ROOT / RATE)],
        cwd=ROOT, env=isolated_env(), text=True,
        capture_output=True, timeout=20, check=False,
    )
    if result.returncode != 0:
        raise AssertionError(UNKNOWN + ": synthetic PHP probe failed")
    try:
        data = json.loads(result.stdout)
    except (ValueError, TypeError) as exc:
        raise AssertionError(UNKNOWN + ": malformed PHP observation") from exc
    if type(data) is not dict or data != EXPECTED:
        raise AssertionError(UNKNOWN + ": PHP witness incomplete or changed")
    return data


def evidence(key: object, files: object, observations: object) -> str:
    """Local evidence is partial; global security/production always UNKNOWN."""
    if (type(key) is not str or key not in IDS or type(files) is not dict
            or set(files) != set(FILES) or type(observations) is not dict
            or observations != EXPECTED
            or any(type(files[path]) is not str or not files[path] for path in FILES)):
        return UNKNOWN
    try:
        rows(files[MATRIX])
    except ValueError:
        return UNKNOWN
    spec = files[SPEC]
    section = "## 42. Security principles" if key.startswith("S42") else "## 43. Migrations"
    if section not in spec or CLAUSES[key] not in spec.split(section, 1)[1]:
        return UNKNOWN
    migration = files[MIGRATIONS]
    rate = files[RATE]
    registry = files[REGISTRY]
    contract = files[MIGRATION_TEST]
    if key == "S42-R010":
        return LOCAL if (
            "function brvtal_password_rate_limit_path" in rate
            and "hash('sha256', $ip . '|' . strtolower(trim($email)))" in rate
            and "brvtal_password_rate_limit_failure" in rate
            and "admin@example.test" in files[AUTH_TEST]
            and observations["safe_key"] is True
            and observations["casefold"] is True
        ) else UNKNOWN
    if key == "S42-R011":
        # A bounded test cannot prove every GitHub commit is secret-free.
        return GAP
    if key == "S43-R001":
        return LOCAL if (
            "function brvtalMigrationAssertAdditiveSql" in migration
            and "function brvtal_migration_checksum" in migration
            and "CREATE TABLE IF NOT EXISTS schema_migrations" in registry
            and "checksum_sha256 CHAR(64) NOT NULL" in registry
            and "MIGRATION_NON_ADDITIVE_SQL" in contract
        ) else UNKNOWN
    if key == "S43-R002":
        # Scanner blocks destructive SQL offline; whether a real production
        # deployment silently changed schema cannot be inferred from that.
        return GAP if "MIGRATION_NON_ADDITIVE_SQL" in migration else UNKNOWN
    return LOCAL if (
        "function brvtalMigrationVerifyPlanStatus" in migration
        and "deploy_sha CHAR(40) NULL" in registry
        and "MIGRATION_PLAN_DB_DRIFT" in migration
        and observations["pending_expected"] == "OK"
        and observations["pending_unexpected"] == "MIGRATION_PLAN_DB_DRIFT"
    ) else UNKNOWN


def explicit_gap(key: object, files: object, observations: object) -> str:
    if key not in GAP_ROWS:
        return UNKNOWN
    status = evidence(key, files, observations)
    if status != GAP:
        return UNKNOWN
    return (str(key) + ": BRECHA_DOCUMENTADA — "
            + ("sin auditoría global de secretos GitHub" if key == "S42-R011"
               else "sin inspección del esquema real ni trazas de mutación en producción")
            + "; estado operativo UNKNOWN")


def operational(_key: object, _claim: object = None) -> str:
    return UNKNOWN


class BrechasBrvtal68Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.files = sources()
        cls.observed = observe_php()

    def test_contrato_principal(self) -> None:
        """AC-01: Every requested row has a local witness or explicit gap."""
        self.assertEqual(set(rows(self.files[MATRIX])), set(IDS))
        self.assertEqual(set(LOCAL_ROWS) | GAP_ROWS, set(IDS))
        for key in IDS:
            with self.subTest(key=key):
                self.assertEqual(rows(self.files[MATRIX])[key][1], CLAUSES[key])
                self.assertEqual(evidence(key, self.files, self.observed),
                                 LOCAL if key in LOCAL_ROWS else GAP)
                self.assertEqual(operational(key, self.observed), UNKNOWN)
                if key in GAP_ROWS:
                    self.assertIn("BRECHA_DOCUMENTADA", explicit_gap(key, self.files, self.observed))
                    self.assertIn("UNKNOWN", explicit_gap(key, self.files, self.observed))

    def test_falla_cerrado(self) -> None:
        """AC-02: Missing/duplicate/altered sources never become success."""
        raw = self.files[MATRIX]
        for key in IDS:
            original = next(line for line in raw.splitlines()
                            if line.startswith(f"| {key} · "))
            for corrupted in (
                raw.replace(original, "", 1),
                raw + "\n" + original,
                raw.replace(original, f"| {key} · malformed |", 1),
                raw.replace(original, original.replace("NO_VERIFICABLE", "VERIFICADO"), 1),
            ):
                with self.subTest(key=key, length=len(corrupted)):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        rows(corrupted)
                    self.assertEqual(evidence(key, {**self.files, MATRIX: corrupted},
                                              self.observed), UNKNOWN)
        for path in FILES:
            damaged = dict(self.files)
            damaged.pop(path)
            for key in IDS:
                self.assertEqual(evidence(key, damaged, self.observed), UNKNOWN)
        self.assertEqual(evidence("S43-R999", self.files, self.observed), UNKNOWN)
        self.assertEqual(evidence(IDS[0], self.files, {"safe_ddl": "OK"}), UNKNOWN)
        self.assertEqual(evidence(IDS[0], self.files, {**EXPECTED, "safe_key": False}), UNKNOWN)
        self.assertEqual(evidence(IDS[2], {**self.files, SPEC: ""}, self.observed), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: Synthetic fixtures, no DB, network or inherited credentials."""
        self.assertEqual(set(self.files), set(FILES))
        self.assertTrue(all((ROOT / name).is_file() for name in FILES))
        env = isolated_env()
        self.assertEqual(env["APP_ENV"], "testing")
        for name in ("DB_PASSWORD", "DATABASE_URL", "MYSQL_PWD", "GH_TOKEN",
                     "GITHUB_TOKEN", "APP_KEY", "HTTP_PROXY", "HTTPS_PROXY"):
            self.assertNotIn(name, env)
        self.assertNotIn("PDO(", PHP_PROBE)
        self.assertNotIn("file_put_contents(", PHP_PROBE)
        self.assertNotIn("http://", PHP_PROBE)
        self.assertNotIn("https://", PHP_PROBE)
        test_code = (ROOT / "tests/test_brechas_brvtal_68.py").read_text(encoding="utf-8")
        for node in ast.walk(ast.parse(test_code)):
            if isinstance(node, ast.Import):
                self.assertTrue(all(alias.name.split(".")[0] in {
                    "ast", "json", "os", "pathlib", "re", "shutil", "subprocess", "unittest"
                } for alias in node.names))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: A green CI or synthetic observation never attests production."""
        for key in IDS:
            with self.subTest(key=key):
                self.assertEqual(rows(self.files[MATRIX])[key][2], "NO_VERIFICABLE")
                for spoofed in (None, True, "success", {"ci": "success"},
                                {"production": "GREEN"}, {"migration": "applied"},
                                {"secrets": "clean"}, {"operator": "approved"}):
                    self.assertEqual(operational(key, spoofed), UNKNOWN)
        self.assertEqual(explicit_gap("S43-R003", self.files, self.observed), UNKNOWN)
        self.assertEqual(explicit_gap("S42-R011", self.files, {}), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
