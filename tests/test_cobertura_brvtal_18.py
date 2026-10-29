#!/usr/bin/env python3
"""#1287: real offline behavior for S42-R010 through S45-R002.

Executes committed PHP authentication and migration libraries with disposable
synthetic fixtures. Browser, production and architectural decisions remain B/C.
No network, credentials, real database connection, deploy or coverage-matrix IO.
"""
from __future__ import annotations

import ast
from functools import lru_cache
import json
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = frozenset((
    "S42-R010", "S42-R011",
    "S43-R001", "S43-R002", "S43-R003", "S43-R004",
    "S44-L686", "S44-L691", "S44-R001", "S44-R003",
    "S44-R004", "S44-R005", "S44-R006", "S44-R007",
    "S44-R008", "S44-R009", "S44-R010", "S44-R011",
    "S45-R001", "S45-R002",
))
A = frozenset(("S42-R010", "S43-R001", "S43-R002", "S43-R003"))
B = {
    "S43-R004": "manual production migration approval and deploy order need external receipts",
    "S44-L686": "actual click counts and usability need an interacting browser",
    "S44-L691": "draft lifecycle requires authenticated UI and persisted database state",
    "S44-R001": "routine administration speed needs interactive browser measurement",
    "S44-R003": "visual media selection requires authenticated browser and media integration",
    "S44-R004": "form clarity needs rendered input, error and accessibility states",
    "S44-R005": "useful warnings need rendered UI and affected user workflows",
    "S44-R006": "consistent navigation needs real viewport and interaction evidence",
    "S44-R008": "tablet/mobile resilience needs responsive browser verification",
    "S44-R009": "persistent desktop sidebar needs browser viewport and DOM evidence",
    "S45-R001": "sidebar persistence across workspace transitions needs a browser",
    "S45-R002": "Events workspace interaction needs authenticated browser evidence",
}
C = {
    "S42-R011": "credential and secret handling is repository hygiene policy, not one runtime function",
    "S44-R007": "recognizable visual language requires human design evaluation",
    "S44-R010": "avoiding duplicate sidebar architecture is a design constraint",
    "S44-R011": "visual indicator preference is a design guideline, not a single executable rule",
}
D: dict[str, str] = {}

# Use the existing functions rather than reimplementing application behavior.
# No PDO is constructed and the only writes target TemporaryDirectory fixtures.
PHP_PROBE = r"""
declare(strict_types=1);
require_once 'config/migrations.php';
require_once 'config/migration_reconcile.php';
require_once 'config/password_rate_limit.php';
require_once 'config/totp_rate_limit.php';

$dir = $argv[1];
$alpha = 'migration_alpha_01.sql';
$beta = 'migration_beta_01.sql';
file_put_contents($dir . '/' . $beta, 'CREATE TABLE IF NOT EXISTS beta_fixture (id INT);');
file_put_contents($dir . '/' . $alpha, 'CREATE TABLE IF NOT EXISTS alpha_fixture (id INT);');
$files = brvtal_migrations_discover($dir);
$errors = static function (callable $action): string {
    try {
        $action();
        return 'NONE';
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
};
$sql = [
    'additive' => 'CREATE TABLE IF NOT EXISTS sample_fixture (id INT);',
    'commented' => "-- DROP TABLE ignored;\nCREATE TABLE additive_fixture (id INT);",
    'drop' => 'DROP TABLE users;',
    'update' => 'UPDATE users SET active=0;',
    'delete' => 'DELETE FROM users;',
    'insert' => "INSERT INTO users VALUES (1);",
    'empty' => ' ',
];
$sqlResults = [];
foreach ($sql as $name => $statement) {
    $sqlResults[$name] = $errors(
        static function () use ($statement): void {
            brvtalMigrationAssertAdditiveSql($statement);
        }
    );
}
$status = [
    'registry_exists' => true,
    'orphaned_records' => [],
    'migrations' => [
        ['migration' => 'migration_schema_migrations_01.sql', 'state' => 'applied'],
        ['migration' => $alpha, 'state' => 'pending'],
    ],
];
$absent = ['complete' => false, 'checks' => ['table:alpha_fixture' => false]];
$present = ['complete' => true, 'checks' => ['table:alpha_fixture' => true]];
$partial = ['complete' => false, 'checks' => [
    'table:alpha_fixture' => true, 'column:alpha_fixture.id' => false
]];
$plan = brvtalMigrationBuildReconciliationPlan($status, [$alpha => $absent], [$alpha => true]);
$blocked = brvtalMigrationBuildReconciliationPlan($status, [$alpha => $absent]);
$baseline = brvtalMigrationBuildReconciliationPlan($status, [$alpha => $present]);
$checks = [
    'valid_expected' => $errors(
        static function () use ($status, $alpha): void {
            brvtalMigrationVerifyPlanStatus($status, $alpha);
        }
    ),
    'none_with_pending' => $errors(
        static function () use ($status): void {
            brvtalMigrationVerifyPlanStatus($status, '__NONE__');
        }
    ),
    'invalid_filename' => $errors(
        static function () use ($status): void {
            brvtalMigrationVerifyPlanStatus($status, '../escape.sql');
        }
    ),
    'missing_registry' => $errors(
        static function () use ($status, $alpha): void {
            brvtalMigrationVerifyPlanStatus(
                array_replace($status, ['registry_exists' => false]), $alpha
            );
        }
    ),
    'checksum_mismatch' => $errors(
        static function () use ($status, $alpha): void {
            $bad = $status;
            $bad['migrations'][1]['state'] = 'checksum_mismatch';
            brvtalMigrationVerifyPlanStatus($bad, $alpha);
        }
    ),
    'orphaned_row' => $errors(
        static function () use ($status, $alpha): void {
            brvtalMigrationVerifyPlanStatus(
                array_replace($status, ['orphaned_records' => ['synthetic_unknown']]), $alpha
            );
        }
    ),
    'missing_proof' => $errors(
        static function () use ($status): void {
            brvtalMigrationBuildReconciliationPlan($status, []);
        }
    ),
    'inconsistent_proof' => $errors(
        static function () use ($alpha): void {
            brvtalMigrationProofDisposition($alpha, [
                'complete' => true, 'checks' => ['table:alpha_fixture' => false],
            ]);
        }
    ),
];
$now = 1800100000;
$ip = '198.51.100.5';
$email = 'malformed+fixture@example.invalid';
$passwordPath = brvtal_password_rate_limit_path($email, $dir, $ip);
$firstPassword = brvtal_password_rate_limit_state($email, $dir, $now, $ip);
for ($i = 0; $i <= BRVTAL_PASSWORD_RATE_LIMIT_MAX_FAILURES; $i++) {
    $limitedPassword = brvtal_password_rate_limit_failure($email, $dir, $now + $i, $ip);
}
$otherIp = brvtal_password_rate_limit_state($email, $dir, $now + 7, '198.51.100.6');
$passwordReset = brvtal_password_rate_limit_reset($email, $dir, $ip);
$afterReset = brvtal_password_rate_limit_state($email, $dir, $now + 8, $ip);
for ($i = 0; $i <= BRVTAL_TOTP_RATE_LIMIT_MAX_FAILURES; $i++) {
    $limitedTotp = brvtal_totp_rate_limit_failure(42, 'login', $dir, $now + $i, $ip);
}
$otherScope = brvtal_totp_rate_limit_state(42, 'disable', $dir, $now + 8, $ip);
$normalized = brvtal_password_rate_limit_normalize(
    ['attempts' => [$now - 1, $now - 1200, 'invalid', null],
     'blocked_until' => $now - 1],
    $now
);
echo json_encode([
    'migration_names' => array_keys($files),
    'sha256' => brvtal_migration_checksum($files[$alpha]),
    'sha256_expected' => hash_file('sha256', $files[$alpha]),
    'sql' => $sqlResults,
    'verify' => $checks,
    'proof_disposition' => [
        'absent' => brvtalMigrationProofDisposition($alpha, $absent),
        'present' => brvtalMigrationProofDisposition($alpha, $present),
        'partial' => brvtalMigrationProofDisposition($alpha, $partial),
    ],
    'plan' => $plan,
    'blocked' => $blocked,
    'baseline' => $baseline,
    'auth' => [
        'path_name' => basename($passwordPath),
        'initial_limited' => $firstPassword['limited'],
        'limited' => $limitedPassword['limited'],
        'retry_after' => $limitedPassword['retry_after'],
        'attempts' => count($limitedPassword['attempts']),
        'ip_isolated' => !$otherIp['limited'] && $otherIp['attempts'] === [],
        'password_reset' => $passwordReset,
        'after_reset' => !$afterReset['limited'] && $afterReset['attempts'] === [],
        'totp_limited' => $limitedTotp['limited'],
        'totp_scope_isolated' => !$otherScope['limited'],
        'normalized' => $normalized,
    ],
], JSON_THROW_ON_ERROR);
"""


@lru_cache(maxsize=1)
def php_behavior() -> dict:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP CLI is required")
    with tempfile.TemporaryDirectory(prefix="brvtal-1287-fixture-") as directory:
        run = subprocess.run(
            [php, "-d", "allow_url_fopen=0", "-r", PHP_PROBE, "--", directory],
            cwd=ROOT, capture_output=True, text=True, timeout=60, check=False,
        )
    if run.returncode:
        raise AssertionError(
            "UNKNOWN: application PHP probe failed: " + run.stderr[-1200:]
        )
    try:
        result = json.loads(run.stdout)
    except json.JSONDecodeError as error:
        raise AssertionError("UNKNOWN: malformed application PHP output") from error
    if not isinstance(result, dict):
        raise AssertionError("UNKNOWN: PHP probe must return an object")
    return result


class CoberturaBrvtal18Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        self.assertEqual(len(IDS), 20)
        self.assertEqual(A | B.keys() | C.keys() | D.keys(), IDS)
        self.assertFalse((A & B.keys()) or (A & C.keys()))
        probe = php_behavior()
        auth = probe["auth"]
        evidence = {
            "S42-R010": (
                re.fullmatch(r"[a-f0-9]{64}\.json", auth["path_name"]) is not None
                and auth["initial_limited"] is False
                and auth["limited"] is True
                and auth["retry_after"] > 0
                and auth["attempts"] == 6
                and auth["ip_isolated"] is True
                and auth["totp_limited"] is True
                and auth["totp_scope_isolated"] is True
            ),
            "S43-R001": (
                probe["migration_names"] ==
                    ["migration_alpha_01.sql", "migration_beta_01.sql"]
                and re.fullmatch(r"[a-f0-9]{64}", probe["sha256"]) is not None
                and probe["sha256"] == probe["sha256_expected"]
                and probe["proof_disposition"]["absent"] == "apply"
                and probe["proof_disposition"]["present"] == "baseline"
                and probe["proof_disposition"]["partial"] == "blocked"
                and probe["plan"]["apply"] == ["migration_alpha_01.sql"]
                and probe["baseline"]["baseline"] == ["migration_alpha_01.sql"]
            ),
            "S43-R002": (
                probe["sql"]["additive"] == "NONE"
                and probe["sql"]["commented"] == "NONE"
                and all(probe["sql"][kind] == "MIGRATION_NON_ADDITIVE_SQL"
                        for kind in ("drop", "update", "delete", "insert"))
                and probe["blocked"]["apply"] == []
                and probe["blocked"]["blocked"] == ["migration_alpha_01.sql"]
            ),
            "S43-R003": (
                probe["verify"]["valid_expected"] == "NONE"
                and probe["verify"]["none_with_pending"] == "MIGRATION_PLAN_DB_DRIFT"
                and probe["plan"]["pending"] == ["migration_alpha_01.sql"]
                and probe["plan"]["record_registry_migration"] is False
                and probe["baseline"]["apply"] == []
            ),
        }
        self.assertEqual(set(evidence), A)
        for row, success in evidence.items():
            with self.subTest(row=row):
                self.assertTrue(success, row + ": actual PHP behavior regressed")

    def test_falla_cerrado(self) -> None:
        probe = php_behavior()
        self.assertEqual(probe["sql"]["empty"], "MIGRATION_SQL_EMPTY")
        failures = {
            "invalid_filename": "INVALID_MIGRATION_NAME",
            "missing_registry": "MIGRATION_REGISTRY_MISSING",
            "checksum_mismatch": "MIGRATION_CHECKSUM_MISMATCH",
            "orphaned_row": "MIGRATION_PLAN_DB_DRIFT",
            "missing_proof": "MIGRATION_SCHEMA_PROOF_MISSING:migration_alpha_01.sql",
            "inconsistent_proof": "MIGRATION_SCHEMA_PROOF_INVALID:migration_alpha_01.sql",
        }
        for case, expected in failures.items():
            with self.subTest(case=case):
                self.assertEqual(probe["verify"][case], expected)
        auth = probe["auth"]
        self.assertTrue(auth["password_reset"])
        self.assertTrue(auth["after_reset"])
        self.assertEqual(auth["normalized"],
                         {"attempts": [1800099999], "blocked_until": 0})

    def test_sin_datos_reales(self) -> None:
        self.assertEqual(len(IDS), len(A) + len(B) + len(C) + len(D))
        self.assertEqual((len(A), len(B), len(C), len(D)), (4, 12, 4, 0))
        for reasons in (B, C, D):
            self.assertTrue(all(key in IDS and reason for key, reason in reasons.items()))
        own = Path(__file__).read_text(encoding="utf-8")
        forbidden = {"requests", "httpx", "socket", "urllib", "pymysql", "psycopg2"}
        for node in ast.walk(ast.parse(own)):
            if isinstance(node, ast.Import):
                self.assertFalse({alias.name.split(".")[0] for alias in node.names}
                                 & forbidden)
            elif isinstance(node, ast.ImportFrom) and node.module:
                self.assertNotIn(node.module.split(".")[0], forbidden)
        self.assertNotRegex(PHP_PROBE, r"(?i)https?://|curl_exec|mysqli_connect")
        self.assertIn("TemporaryDirectory", own)
        self.assertIn("allow_url_fopen=0", own)
        self.assertEqual(php_behavior()["verify"]["valid_expected"], "NONE")


if __name__ == "__main__":
    unittest.main()
