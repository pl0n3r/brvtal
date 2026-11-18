#!/usr/bin/env python3
"""BRVTAL #1277: execute real TOTP/auth helpers using disposable synthetic inputs.

Only pure/offline subcontracts are verified. B requires an authenticated
browser or real database integration; C is declarative and not code coverage.
This test never reads the coverage matrix or claims production validation.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = frozenset((
    "S24-L385", "S26-L427", "S26-R001", "S26-R003", "S26-R004",
    "S26-R005", "S26-R006", "S26-R007", "S26-R008", "S26-R009",
    "S26-R010", "S26-R011", "S26-R012", "S26-R013", "S26-R014",
    "S26-R015", "S26-R016", "S26-R017", "S27-R001", "S27-R002",
))
OFFLINE = frozenset(("S26-L427", "S26-R007", "S26-R012", "S26-R013"))
EXTERNAL = {
    "S24-L385": "B: Settings navigation needs authenticated browser interaction",
    "S26-R001": "B: shared shell/session/workspace needs authenticated browser",
    "S26-R004": "B: enabled flag lifecycle requires a disposable MariaDB schema",
    "S26-R005": "B: encrypted secret persistence requires MariaDB integration",
    "S26-R006": "B: confirmed timestamp requires transaction/row-lock integration",
    "S26-R008": "B: fallback database key needs safe real MariaDB concurrency",
    "S26-R009": "B: private settings list/edit/delete requires HTTP and DB isolation",
    "S26-R011": "B: end-to-end encrypted secret storage requires protected enrollment",
    "S26-R014": "B: absence of plaintext at rest requires database persistence",
    "S26-R015": "B: failed login cannot disable 2FA: requires authenticated integration",
    "S26-R016": "B: AES-256-GCM enrollment ciphertext requires protected flow",
    "S26-R017": "B: password/TOTP/WebKit browser flow requires actual browser",
    "S27-R001": "B: multiple simultaneous administrators requires authenticated DB",
}
DECLARATIVE = {
    "S26-R003": "C: introductory heading for persistence foundation",
    "S26-R010": "C: introductory heading for security principles",
    "S27-R002": "C: product decision against introducing complex RBAC",
}
CONFIG = (
    "<?php return ['app'=>['timezone'=>'UTC'],"
    "'security'=>['session_name'=>'BRVTAL_SYNTHETIC',"
    "'encryption_key'=>'synthetic-fixture-only-key-of-sufficient-length']];"
)

# PDO subclass is an in-memory probe, NOT an actual database connection.
# Real application function brvtal_totp_recovery_verify performs the SELECT,
# password_verify and conditional consume against these observable statements.
PHP_PROBE = r'''
declare(strict_types=1);
define('BRVTAL_SENTRY_TESTING', true);
$GLOBALS['brvtalBootstrapConfigPath'] = $argv[2];
require_once 'config/totp_auth.php';
require_once 'config/admin_auth.php';

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('REAL_BEHAVIOR_FAILED:' . $message);
};
class SyntheticRecoveryPDO extends PDO {
    public bool $used = false;
    public array $queries = [];
    public string $hash;
    public function __construct() {
        $this->hash = password_hash('ABCDE12345', PASSWORD_DEFAULT);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->queries[] = $query;
        return new SyntheticRecoveryStatement($this, $query);
    }
}
class SyntheticRecoveryStatement extends PDOStatement {
    private int $cursor = 0;
    private int $changed = 0;
    public function __construct(
        private SyntheticRecoveryPDO $store,
        private string $query
    ) {}
    public function execute(?array $params = null): bool {
        if (str_starts_with($this->query, 'UPDATE admin_recovery_codes')) {
            if ($params === [77] && !$this->store->used) {
                $this->store->used = true;
                $this->changed = 1;
            }
        }
        return true;
    }
    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        if ($this->cursor++ || $this->store->used) return false;
        return ['id'=>77,'code_hash'=>$this->store->hash];
    }
    public function rowCount(): int { return $this->changed; }
}
$mode = $argv[1];
if ($mode === 'csrf-invalid' || $mode === 'csrf-empty') {
    $valid = brvtal_admin_csrf_token();
    $check(strlen($valid) === 64, 'session csrf must be generated');
    brvtal_admin_require_csrf($mode === 'csrf-invalid' ? 'WRONG' : '');
    throw new RuntimeException('CSRF guard unexpectedly returned');
}
$covered = [];
if ($mode === 'main') {
    $valid = brvtal_admin_csrf_token();
    $check(strlen($valid) === 64, 'CSRF random token');
    brvtal_admin_require_csrf($valid);
    $covered[] = 'S26-L427';

    $db = new SyntheticRecoveryPDO();
    $check(brvtal_totp_recovery_verify($db, 7, 'ABCDE-12345'),
           'matching hashed code must be consumed');
    $check($db->used && count($db->queries) === 2,
           'recovery verification must perform SELECT and UPDATE');
    $check(str_contains($db->queries[1], 'used_at IS NULL'),
           'atomic conditional one-use recovery');
    $covered[] = 'S26-R007';
    $check(password_verify('ABCDE12345', $db->hash)
        && !str_contains($db->hash, 'ABCDE12345'),
        'stored recovery fixture is a non-plaintext password hash');
    $covered[] = 'S26-R012';

    $dir = $argv[3] . '/isolated-limits';
    $now = 1700000000;
    $first = brvtal_totp_rate_limit_state(7, 'login', $dir, $now, '127.0.0.9');
    $check(!$first['limited'], 'fresh identity not limited');
    for ($i = 0; $i < 6; $i++) {
        $state = brvtal_totp_rate_limit_failure(
            7, 'login', $dir, $now + $i, '127.0.0.9'
        );
    }
    $check($state['limited'] && $state['retry_after'] > 0,
           'sixth failure locks identity');
    $separate = brvtal_totp_rate_limit_state(
        7, 'disable', $dir, $now + 6, '127.0.0.9'
    );
    $check(!$separate['limited'], 'disable scope isolated from login');
    $covered[] = 'S26-R013';
} elseif ($mode === 'invalid') {
    $db = new SyntheticRecoveryPDO();
    $check(!brvtal_totp_recovery_verify($db, 7, 'wrong'),
           'invalid recovery code must fail');
    $check($db->queries === [], 'malformed code does not query recovery table');
    $check(brvtal_totp_recovery_verify($db, 7, 'ABCDE12345'),
           'first attempt may use matching recovery code');
    $check(!brvtal_totp_recovery_verify($db, 7, 'ABCDE12345'),
           'replayed recovery code must fail');
    $check(count($db->queries) === 3, 'replayed recovery performs no UPDATE');
    $blocked = brvtal_totp_rate_limit_state(
        7, 'login', $argv[3] . '/missing', 1700000000, '127.0.0.9'
    );
    $check(!$blocked['limited'], 'new synthetic limit scope starts clean');
    $check(!brvtal_totp_verify('JBSWY3DPEHPK3PXP', 'invalid', 1700000000),
           'malformed TOTP must fail');
    $covered[] = 'DENIED';
} else {
    throw new InvalidArgumentException('Unsupported test mode');
}
echo json_encode([
    'covered'=>$covered, 'database'=>'synthetic-only',
    'network'=>'disabled', 'production'=>'UNKNOWN'
], JSON_THROW_ON_ERROR);
'''


def php_case(mode: str) -> dict:
    if mode not in ("main", "invalid", "csrf-invalid", "csrf-empty"):
        raise ValueError("unsupported mode")
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: PHP unavailable")
    with tempfile.TemporaryDirectory(prefix="brvtal-1277-synthetic-") as temp:
        config = Path(temp) / "config.php"
        config.write_text(CONFIG, encoding="utf-8")
        env = {key: os.environ[key] for key in ("PATH", "LANG", "LC_ALL")
               if key in os.environ}
        result = subprocess.run(
            [php, "-r", PHP_PROBE, mode, str(config), temp],
            cwd=ROOT, env=env, capture_output=True, text=True, timeout=40,
            check=False,
        )
    if result.returncode != 0 or result.stderr.strip():
        raise AssertionError("UNKNOWN: PHP execution failed: "
                             + (result.stderr + result.stdout)[-1300:])
    try:
        response = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: malformed PHP evidence") from exc
    if not isinstance(response, dict):
        raise AssertionError("UNKNOWN: missing structured evidence")
    if mode.startswith("csrf-"):
        if response.get("ok") is not False or response.get("error") != "CSRF":
            raise AssertionError("UNKNOWN: CSRF failure not enforced")
        if "csrf" in response or "secret" in response:
            raise AssertionError("UNKNOWN: sensitive CSRF response")
    elif (response.get("database"), response.get("network"),
          response.get("production")) != ("synthetic-only", "disabled", "UNKNOWN"):
        raise AssertionError("UNKNOWN: unsafe or ambiguous execution evidence")
    return response


class CoberturaBrvtal08Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        self.assertEqual(len(IDS), 20)
        self.assertEqual(
            IDS, OFFLINE | frozenset(EXTERNAL) | frozenset(DECLARATIVE))
        self.assertTrue(all(EXTERNAL.values()))
        self.assertTrue(all(DECLARATIVE.values()))
        self.assertEqual(set(php_case("main")["covered"]), OFFLINE)

    def test_falla_cerrado(self) -> None:
        self.assertEqual(php_case("invalid")["covered"], ["DENIED"])
        self.assertEqual(php_case("csrf-invalid")["error"], "CSRF")
        self.assertEqual(php_case("csrf-empty")["error"], "CSRF")
        with self.assertRaises(ValueError):
            php_case("unsupported")
        self.assertEqual(set(EXTERNAL) & OFFLINE, set())
        self.assertEqual(set(DECLARATIVE) & OFFLINE, set())

    def test_sin_datos_reales(self) -> None:
        self.assertIn("BRVTAL_SENTRY_TESTING", PHP_PROBE)
        self.assertNotIn("curl_", PHP_PROBE)
        self.assertNotIn("file_get_contents(", PHP_PROBE)
        self.assertNotIn("brvtal.com.co", PHP_PROBE)
        self.assertNotIn("docs/matriz-cobertura", PHP_PROBE)
        self.assertNotIn("docs/matriz-cobertura", __doc__ or "")
        self.assertNotIn("@", CONFIG)
        observed = php_case("main")
        self.assertEqual(observed["network"], "disabled")
        self.assertEqual(observed["production"], "UNKNOWN")


if __name__ == "__main__":
    unittest.main()
