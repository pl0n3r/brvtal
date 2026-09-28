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


if __name__ == "__main__":
    unittest.main()
