import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class DatabaseFailureDiagnosticsTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.bootstrap = (ROOT / "config/bootstrap.php").read_text(encoding="utf-8")
        cls.auth = (ROOT / "config/admin_auth.php").read_text(encoding="utf-8")
        cls.api = (ROOT / "api/index.php").read_text(encoding="utf-8")
        cls.smoke = (ROOT / "tests/e2e/production-authenticated-smoke.mjs").read_text(encoding="utf-8")
        cls.workflow = (ROOT / ".github/workflows/production-authenticated-smoke.yml").read_text(encoding="utf-8")

    def _auth_state(self):
        match = re.search(
            r"function brvtal_admin_authentication_state\(\): string\n\{(?P<body>.*?)\n\}",
            self.auth,
            flags=re.S,
        )
        self.assertIsNotNone(match)
        return match.group("body")

    def _health_block(self):
        start = self.api.index("    if ($resource==='health') {")
        end = self.api.index("\n\n    if ($resource==='auth') {", start)
        return self.api[start:end]

    def test_auth_revalidation_distinguishes_connect_from_query_failures(self):
        body = self._auth_state()
        self.assertIn("$pdo = db();", body)
        self.assertIn("brvtal_admin_account_session_state($pdo, $adminId)", body)
        self.assertIn("'DB_CONNECT_UNAVAILABLE'", body)
        self.assertIn("'AUTH_REVALIDATION_QUERY_UNAVAILABLE'", body)
        self.assertIn("'phase' => 'connect'", body)
        self.assertIn("'phase' => 'query'", body)
        self.assertNotIn("$e->getMessage()", body)
        self.assertIn("brvtal_admin_revalidation_failure_code()", self.auth)
        self.assertIn("$payload['code'] = $code;", self.auth)

    def test_api_health_distinguishes_connect_from_query_failures(self):
        block = self._health_block()
        self.assertIn("'code'=>'DB_CONNECT_UNAVAILABLE'", block)
        self.assertIn("'code'=>'DB_QUERY_UNAVAILABLE'", block)
        self.assertIn("'phase'=>'connect'", block)
        self.assertIn("'phase'=>'query'", block)
        self.assertNotIn("$e->getMessage()", block)
        self.assertLess(block.index("$pdo=db();"), block.index("$pdo->query('SELECT 1');"))

    def test_diagnostics_add_no_retry_or_persistent_authority_cache(self):
        auth_body = self._auth_state()
        health = self._health_block()
        db_match = re.search(r"function db\(\): PDO \{(?P<body>.*?)\n\}", self.bootstrap, flags=re.S)
        self.assertIsNotNone(db_match)
        db_body = db_match.group("body")
        for source in (auth_body, health, db_body):
            self.assertNotIn("PDO::ATTR_PERSISTENT", source)
            self.assertNotRegex(source, r"\b(?:sleep|usleep)\s*\(")
            self.assertNotRegex(source, r"\b(?:for|while)\s*\(")
        self.assertEqual(auth_body.count("brvtal_admin_account_session_state($pdo, $adminId)"), 1)

    def test_post_merge_smoke_contract_stays_exact_main_and_fail_closed(self):
        self.assertIn("ref: main", self.workflow)
        self.assertIn('BRVTAL_EXPECTED_SHA=$(git rev-parse HEAD)', self.workflow)
        self.assertIn("if (response.status() >= 500)", self.smoke)
        self.assertIn("Authenticated DISCADMIN emitted HTTP 5xx", self.smoke)
        self.assertIn("['code', 'code']", self.smoke)
        self.assertIn("sanitizeServerErrorToken(payload[sourceField])", self.smoke)


if __name__ == "__main__":
    unittest.main()
