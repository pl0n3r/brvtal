"""Runtime regression: bounded, fail-closed DB connect recovery.

No production credentials, SQL or live DB connections are required.
"""
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]


def run_php(source: str) -> str:
    process = subprocess.run(
        ["php", "-r", source], cwd=ROOT, capture_output=True,
        text=True, check=False, timeout=8,
    )
    if process.returncode:
        raise AssertionError(f"PHP script failed: {process.stderr}")
    return process.stdout.strip()


BOOT = "require 'config/db_connect_recovery.php';"
TRANSIENT = (
    "$e = new PDOException('sensitive content not logged', 0);"
    "$e->errorInfo = ['HY000', 1040, 'sensitive detail'];"
)


class DatabaseConnectionRecoveryTests(unittest.TestCase):
    def test_bounded_connect_failure_recovery(self):
        result = run_php(
            BOOT + TRANSIENT
            + "$tries=0;$wait=[];"
            + "$result=brvtal_db_connect_with_recovery("
            + "function() use (&$tries,$e) {$tries++; if($tries===1) throw $e; return 'ready';},"
            + "function($time) use (&$wait) {$wait[]=$time;});"
            + "echo json_encode([$result,$tries,$wait]);"
        )
        self.assertEqual(result, '["ready",2,[50000]]')

        result = run_php(
            BOOT + TRANSIENT + "$tries=0;$wait=[];"
            + "try{brvtal_db_connect_with_recovery("
            + "function() use (&$tries,$e){$tries++;throw $e;},"
            + "function($t) use (&$wait){$wait[]=$t;});}catch(PDOException $caught){}"
            + "echo json_encode([$tries,$wait]);"
        )
        self.assertEqual(result, '[2,[50000]]')

    def test_no_query_or_mutation_retry(self):
        # Credential failures, unknown errors, and SQL state 42 are not connection
        # transients. In each case exactly one invocation is allowed.
        for state, code in (("28000", 1045), ("HY000", 1045), ("42000", 1064),
                            ("HY000", 0), ("08S01", 9999)):
            result = run_php(
                BOOT + f"$e=new PDOException('secret',0);$e->errorInfo=['{state}',{code},'private'];"
                + "$tries=0;$wait=0;"
                + "try{brvtal_db_connect_with_recovery("
                + "function()use(&$tries,$e){$tries++;throw $e;},"
                + "function($t)use(&$wait){$wait++;});}catch(PDOException $caught){}"
                + "echo json_encode([$tries,$wait]);"
            )
            self.assertEqual(result, '[1,0]', (state, code))

        recovery = (ROOT / 'config/db_connect_recovery.php').read_text()
        auth = (ROOT / 'config/admin_auth.php').read_text()
        bootstrap = (ROOT / 'config/bootstrap.php').read_text()
        self.assertIn("brvtal_db_connect_with_recovery(static fn(): PDO => new PDO(", bootstrap)
        self.assertNotIn("->query(", recovery)
        self.assertNotIn("->execute(", recovery)
        self.assertNotIn("->prepare(", recovery)
        self.assertIn('return BRVTAL_ADMIN_AUTH_UNAVAILABLE;', auth)
        self.assertIn('$unavailable ? 503 : 401', auth)

    def test_recovery_has_no_secret_logging_or_implicit_non_pdo_retry(self):
        result = run_php(
            BOOT + "$tries=0;$wait=0;"
            + "try{brvtal_db_connect_with_recovery("
            + "function()use(&$tries){$tries++;throw new RuntimeException('secret');},"
            + "function($t)use(&$wait){$wait++;});}catch(RuntimeException $caught){}"
            + "echo json_encode([$tries,$wait]);"
        )
        self.assertEqual(result, '[1,0]')
        source = (ROOT / 'config/db_connect_recovery.php').read_text()
        self.assertNotIn('$error->getMessage()', source)
        self.assertNotIn('$error->getTrace', source)
        self.assertNotIn('errorInfo[2]', source)
        self.assertIn('usleep(50000)', source)


if __name__ == '__main__':
    unittest.main()
