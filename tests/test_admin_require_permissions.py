#!/usr/bin/env python3
"""Real PHP fail-closed admin/session and staff-origin regressions, #1325.

All inputs are disposable. No production database, secrets or network transport.
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
CONFIG = """<?php
return [
  'app' => ['timezone' => 'UTC'],
  'security' => ['session_name' => 'BRVTAL_AUTH_TEST'],
  'db' => [
    'host' => new class {
      public function __toString(): string {
        throw new RuntimeException('SYNTHETIC_DB_UNAVAILABLE');
      }
    },
    'port' => 3306,
    'name' => 'fixture',
    'charset' => 'utf8mb4',
    'user' => 'fixture',
    'pass' => 'fixture',
  ],
];
"""
SESSION_PROBE = r"""
define('BRVTAL_SENTRY_TESTING', true);
$GLOBALS['brvtalBootstrapConfigPath'] = $argv[2];
$GLOBALS['brvtalSentryTestLogFile'] = $argv[3];
register_shutdown_function(static function (): void {
    echo "\n@@HTTP_STATUS@@", http_response_code();
});
require 'config/admin_auth.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
brvtal_admin_session_start();
if ($argv[1] === 'unavailable') {
    $_SESSION['admin_id'] = 17;
    $_SESSION['issued_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['credential_epoch'] = 1;
} elseif ($argv[1] === 'absent') {
    unset($_SESSION['admin_id']);
} else {
    throw new RuntimeException('INVALID_SYNTHETIC_MODE');
}
brvtal_admin_require();
throw new RuntimeException('AUTHENTICATION_BYPASS');
"""
ORIGIN_PROBE = r"""
putenv('BRVTAL_CONTROLBOT_STAFF_KEY_ID=fixture-key');
putenv('BRVTAL_CONTROLBOT_STAFF_KEY=fixture-secret-not-real');
putenv('BRVTAL_CONTROLBOT_STAFF_ALLOWLIST=127.0.0.1');
require 'config/admin_staff_ops.php';
$server = [
  'HTTPS' => $argv[1] === 'insecure' ? 'off' : 'on',
  'REMOTE_ADDR' => $argv[1] === 'foreign' ? '203.0.113.27' : '127.0.0.1',
];
try {
    brvtalStaffOpsAuthenticate($server, '', 1000, $argv[2]);
    echo json_encode(['ok' => true]);
} catch (BrvtalStaffOpsHttpError $error) {
    echo json_encode(['ok' => false, 'status' => $error->status,
                      'error' => $error->getMessage()]);
}
"""


def run_php(source: str, mode: str, temp: Path, args: list[str]) -> str:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("PHP CLI unavailable; no authorization evidence")
    env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"),
           "HOME": str(temp), "TMPDIR": str(temp),
           "BRVTAL_SENTRY_DSN": ""}
    result = subprocess.run(
        [php, "-d", "session.save_path=" + str(temp),
         "-d", "display_errors=stderr", "-r", source, mode, *args],
        cwd=ROOT, env=env, text=True, capture_output=True,
        timeout=15, check=False,
    )
    if result.returncode != 0 or result.stderr.strip():
        raise AssertionError(
            f"PHP fixture {mode} failed: "
            f"{(result.stdout + result.stderr)[-900:]}"
        )
    return result.stdout


class AdminRequirePermissionsTests(unittest.TestCase):
    def test_auth_fail_closed(self) -> None:
        # brvtal_admin_require() itself returns 401 or 503, NOT role-based 403.
        for mode, expected, code in [
            ("absent", 401, "AUTH_REQUIRED"),
            ("unavailable", 503, "AUTH_REVALIDATION_UNAVAILABLE"),
        ]:
            with self.subTest(mode=mode), tempfile.TemporaryDirectory() as name:
                root = Path(name)
                config = root / "config.php"
                config.write_text(CONFIG, encoding="utf-8")
                result = run_php(
                    SESSION_PROBE, mode, root,
                    [str(config), str(root / "fixture.log")],
                )
                payload_text, marker, status_text = result.partition(
                    "\n@@HTTP_STATUS@@"
                )
                self.assertTrue(marker, result)
                self.assertEqual(int(status_text.strip()), expected)
                payload = json.loads(payload_text)
                self.assertIs(payload["ok"], False)
                self.assertEqual(payload["error"], code)
                if mode == "unavailable":
                    self.assertEqual(payload["code"], "DB_CONNECT_UNAVAILABLE")
                else:
                    self.assertNotIn("code", payload)
                self.assertNotIn("SYNTHETIC_DB_UNAVAILABLE", payload_text)

        # Actual staff-operations guard is a separate 403 trust boundary.
        for mode, reason in [
            ("insecure", "HTTPS_REQUIRED"),
            ("foreign", "ORIGIN_NOT_ALLOWED"),
        ]:
            with self.subTest(mode=mode), tempfile.TemporaryDirectory() as name:
                payload = json.loads(run_php(ORIGIN_PROBE, mode, Path(name), [name]))
                self.assertIs(payload["ok"], False)
                self.assertEqual(payload["status"], 403)
                self.assertEqual(payload["error"], reason)

    def test_invalid_session_does_not_touch_database(self) -> None:
        # An unauthenticated request must exit before attempting db().
        with tempfile.TemporaryDirectory() as name:
            root = Path(name)
            config = root / "config.php"
            config.write_text(CONFIG, encoding="utf-8")
            result = run_php(
                SESSION_PROBE, "absent", root,
                [str(config), str(root / "fixture.log")],
            )
            self.assertNotIn("DB_CONNECT_UNAVAILABLE", result)
            self.assertNotIn("SYNTHETIC_DB_UNAVAILABLE", result)

    def test_fixtures_are_local_and_non_authoritative(self) -> None:
        self.assertIn("BRVTAL_SENTRY_TESTING", SESSION_PROBE)
        self.assertIn("throw new RuntimeException", CONFIG)
        self.assertNotIn("curl_exec(", SESSION_PROBE + ORIGIN_PROBE)
        self.assertNotIn("file_get_contents('http", SESSION_PROBE + ORIGIN_PROBE)
        self.assertNotIn("BRVTAL_SENTRY_DSN", CONFIG)
        self.assertNotIn("PRODUCTION", CONFIG)


if __name__ == "__main__":
    unittest.main()
