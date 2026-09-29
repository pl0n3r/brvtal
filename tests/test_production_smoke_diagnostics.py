import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SMOKE = ROOT / "tests" / "e2e" / "production-authenticated-smoke.mjs"


class ProductionSmokeDiagnosticsTests(unittest.TestCase):
    def test_auth_and_ui_failures_are_safely_attributed(self):
        source = SMOKE.read_text(encoding="utf-8")

        self.assertIn("authentication: { totp: false, http401: [], failureSnapshot: null }", source)
        self.assertIn("if (response.status() === 401)", source)
        self.assertIn("evidence.authentication.http401.push", source)
        self.assertIn("failureSnapshot = await page.evaluate", source)
        self.assertIn("stateAuthed:", source)
        self.assertIn("modalOpen:", source)
        self.assertIn("authDiagnostics:", source)
        self.assertIn("navigation:", source)

        # Production evidence may classify a failure, but it must never persist
        # authentication material or user credentials.
        diagnostic_block = re.search(
            r"failureSnapshot = await page\.evaluate\(\(\) => \(\{(?P<body>.*?)\}\)\);",
            source,
            flags=re.S,
        )
        self.assertIsNotNone(diagnostic_block)
        body = diagnostic_block.group("body")
        for forbidden in ("csrf", "cookie", "password", "totpSecret", "adminEmail"):
            self.assertNotIn(forbidden.lower(), body.lower())

        response_block = re.search(
            r"if \(response\.status\(\) === 401\) \{(?P<body>.*?)\n    \}",
            source,
            flags=re.S,
        )
        self.assertIsNotNone(response_block)
        self.assertIn("path: url.pathname", response_block.group("body"))
        self.assertNotIn("url.search", response_block.group("body"))

    def test_server_5xx_remain_fatal(self):
        source = SMOKE.read_text(encoding="utf-8")

        self.assertIn("if (response.status() >= 500)", source)
        self.assertIn("serverErrors.push(item)", source)
        self.assertIn("if (serverErrors.length)", source)
        self.assertIn("Authenticated DISCADMIN emitted HTTP 5xx", source)

    def test_server_error_evidence_is_sanitized_and_attributed(self):
        source = SMOKE.read_text(encoding="utf-8")

        self.assertIn("const SERVER_ERROR_SAFE_FIELDS = ['error', 'status', 'database'];", source)
        self.assertIn("function sanitizeServerErrorPayload(payload)", source)
        self.assertIn("path: url.pathname", source)
        self.assertIn("status: response.status()", source)
        self.assertIn("stage: evidence.execution.stage", source)
        self.assertIn("operation: evidence.execution.operation || null", source)
        self.assertIn("slice(0, 96)", source)

        listener = re.search(
            r"if \(response\.status\(\) >= 500\) \{(?P<body>.*?)\n    \}",
            source,
            flags=re.S,
        )
        self.assertIsNotNone(listener)
        body = listener.group("body").lower()
        for forbidden in ("cookie", "password", "csrf", "authorization", "adminemail", "totpsecret"):
            self.assertNotIn(forbidden, body)

    def test_transient_auth_db_and_application_failures_are_distinguished(self):
        source = SMOKE.read_text(encoding="utf-8")

        self.assertIn("function classifyServerError(path, safePayload)", source)
        self.assertIn("AUTH_REVALIDATION_UNAVAILABLE", source)
        self.assertIn("return 'auth-revalidation'", source)
        self.assertIn("return 'db-health'", source)
        self.assertIn("return 'application'", source)
        self.assertIn("await Promise.allSettled(serverErrorDiagnostics)", source)

    def test_existing_functional_checks_remain_required(self):
        source = SMOKE.read_text(encoding="utf-8")

        self.assertIn("Production health/version/SHA/database does not match exact main.", source)
        self.assertIn("Production has no dated Event available for the #123 read-only verification.", source)
        self.assertIn("Production needs at least one published Artist and one published Event to verify #124", source)
        self.assertIn("#125 Dashboard transition", source)
        self.assertIn("if (evidence.blockedMutations.length)", source)


if __name__ == "__main__":
    unittest.main()
