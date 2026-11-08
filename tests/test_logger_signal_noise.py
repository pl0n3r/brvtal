"""Comprobar el logger real con UTC inequívoco y correlation ID sin PII."""
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]


class LoggerSignalNoiseTests(unittest.TestCase):
    def test_consistent_timestamp_timezone_and_reduced_noise(self):
        code = """
            define('BRVTAL_SENTRY_TESTING', true);
            $logFile = tempnam(sys_get_temp_dir(), 'brvtal-1655-log-');
            $GLOBALS['brvtalSentryTestLogFile'] = $logFile;
            $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
            $_SERVER['REQUEST_URI'] = '/discadmin/logs.php?token=do-not-log-this';
            date_default_timezone_set('Pacific/Honolulu');
            putenv('BRVTAL_LOG_VERBOSE');
            try {
                require 'config/logger.php';
                brvtal_log('BOOT', 'routine boot');
                brvtal_log('DB', 'routine connection');
                brvtal_log('DB_ERROR', 'connect failed');
                brvtal_log('ERROR', 'explicit error');
                @trigger_error('suppressed warning', E_USER_WARNING);
                echo file_get_contents($logFile);
            } finally {
                if (is_file($logFile)) { unlink($logFile); }
            }
        """
        result = subprocess.run(
            ["php", "-d", "error_reporting=32767", "-d", "display_errors=1", "-r", code],
            cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        lines = [line for line in result.stdout.splitlines() if line.strip()]
        self.assertEqual(len(lines), 2, result.stdout + result.stderr)
        self.assertIn("[ERROR] [DB_ERROR]", lines[0])
        self.assertIn("[ERROR] [ERROR]", lines[1])
        self.assertTrue(all("[ERROR]" in line for line in lines))
        self.assertTrue(all(re.search(r"^\[\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00\]", line) for line in lines))
        request_ids = [re.search(r"\[RID:([0-9a-f]{16})\]", line) for line in lines]
        self.assertTrue(all(request_ids), result.stdout)
        self.assertEqual(request_ids[0].group(1), request_ids[1].group(1))
        self.assertTrue(all("[URI:/discadmin/logs.php]" in line for line in lines))
        self.assertNotIn("do-not-log-this", result.stdout)
        self.assertNotIn("203.0.113.10", result.stdout)
        self.assertNotIn("routine boot", result.stdout)
        self.assertNotIn("routine connection", result.stdout)
        self.assertNotIn("suppressed warning", result.stdout)


if __name__ == "__main__":
    unittest.main()
