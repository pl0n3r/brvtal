import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class AdminReadPressureTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.auth = (ROOT / "discadmin/admin-auth-boundary.js").read_text(encoding="utf-8")
        cls.e2e = (ROOT / "tests/e2e/discadmin-auth-cache.spec.mjs").read_text(encoding="utf-8")

    def test_admin_gets_use_bounded_concurrency_gate(self):
        self.assertIn("const ADMIN_GET_CONCURRENCY = 2;", self.auth)
        self.assertIn("async function acquireAdminGetSlot()", self.auth)
        self.assertIn("function releaseAdminGetSlot()", self.auth)
        self.assertIn("async function boundedAdminGetFetch(input, init)", self.auth)
        self.assertIn("admin GET bursts are bounded to two in-flight requests", self.e2e)
        self.assertIn("adminGetLimit:2", self.e2e)

    def test_auth_and_mutations_bypass_read_gate(self):
        bounded = re.search(
            r"async function boundedAdminGetFetch\(input, init\) \{(?P<body>.*?)\n  \}",
            self.auth,
            flags=re.S,
        )
        self.assertIsNotNone(bounded)
        body = bounded.group("body")
        self.assertIn("requestMethod(input, init) === 'GET'", body)
        self.assertIn("!isAuthRequest(input)", body)
        self.assertIn("if (!shouldBound) return originalFetch(input, init);", body)
        self.assertIn("auth and mutations bypass a saturated admin GET queue", self.e2e)
        self.assertIn("await originalFetch('/api/index.php/auth'", self.auth)

    def test_gate_does_not_add_retry_semantics(self):
        self.assertIn("const retry = await boundedAdminGetFetch(input, init);", self.auth)
        self.assertNotIn("ADMIN_GET_RETRY", self.auth)
        self.assertIn(
            "admin revalidation 503 stays fail-closed without expiring a valid session",
            self.e2e,
        )
        self.assertIn("targetRequests:1", self.e2e)


if __name__ == "__main__":
    unittest.main()
