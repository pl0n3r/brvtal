import json
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SMOKE = ROOT / "tests" / "e2e" / "production-authenticated-smoke.mjs"


class ProductionSmokeDiagnosticsTests(unittest.TestCase):
    def _diagnostics_source(self):
        source = SMOKE.read_text(encoding="utf-8")
        match = re.search(
            r"// BEGIN_SERVER_ERROR_DIAGNOSTICS\n(?P<body>.*?)// END_SERVER_ERROR_DIAGNOSTICS",
            source,
            flags=re.S,
        )
        self.assertIsNotNone(match)
        return source, match.group("body")

    def _run_diagnostics_js(self, expression):
        _, helpers = self._diagnostics_source()
        script = (
            helpers
            + "\nconst __run = async () => { const value = await ("
            + expression
            + "); console.log(JSON.stringify(value)); };"
            + "\n__run().catch(error => { console.error(error); process.exit(1); });"
        )
        completed = subprocess.run(
            ["node", "--input-type=module", "-e", script],
            cwd=ROOT,
            check=True,
            capture_output=True,
            text=True,
            timeout=5,
        )
        return json.loads(completed.stdout.strip())

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
        listener = re.search(
            r"if \(response\.status\(\) >= 500\) \{(?P<body>.*?)\n    \}",
            source,
            flags=re.S,
        )
        self.assertIsNotNone(listener)
        body = listener.group("body")
        self.assertIn("evidence.serverErrors.push(item)", body)
        self.assertIn("writeEvidence()", body)
        self.assertLess(
            body.index("writeEvidence()"),
            body.index("decodeServerErrorPayload(response)"),
        )
        self.assertIn("if (evidence.serverErrors.length)", source)
        self.assertIn("Authenticated DISCADMIN emitted HTTP 5xx", source)

    def test_server_error_evidence_is_sanitized_and_attributed(self):
        result = self._run_diagnostics_js(
            """(async () => {
                const safe = sanitizeServerErrorPayload({
                    error: ' AUTH_REVALIDATION_UNAVAILABLE ',
                    status: 'degraded',
                    database: 'error',
                    code: 'DB_UNAVAILABLE',
                    token: 'do-not-store',
                    email: 'private@example.com'
                });
                return {
                    safe,
                    auth: classifyServerError('/api/index.php/settings', safe),
                    db: classifyServerError('/discadmin/storage-metrics.php', {code: 'DB_UNAVAILABLE'}),
                    application: classifyServerError('/api/index.php/media', {}),
                    json: isJsonContentType('application/json; charset=utf-8'),
                    problemJson: isJsonContentType('application/problem+json; charset=utf-8'),
                    text: isJsonContentType('text/plain')
                };
            })()"""
        )
        self.assertEqual(
            result["safe"],
            {
                "errorCode": "AUTH_REVALIDATION_UNAVAILABLE",
                "payloadStatus": "degraded",
                "database": "error",
                "code": "DB_UNAVAILABLE",
            },
        )
        self.assertEqual(result["auth"], "auth-revalidation")
        self.assertEqual(result["db"], "db-health")
        self.assertEqual(result["application"], "application")
        self.assertTrue(result["json"])
        self.assertTrue(result["problemJson"])
        self.assertFalse(result["text"])

    def test_transient_auth_db_and_application_failures_are_distinguished(self):
        result = self._run_diagnostics_js(
            """(async () => {
                const response = {
                    headers: () => ({'content-type': 'application/problem+json'}),
                    json: async () => ({
                        error: 'AUTH_REVALIDATION_UNAVAILABLE',
                        status: 'degraded',
                        database: 'connected',
                        password: 'must-not-survive'
                    })
                };
                const safe = await decodeServerErrorPayload(response, 50);
                return {
                    safe,
                    category: classifyServerError('/api/index.php/settings', safe)
                };
            })()"""
        )
        self.assertEqual(result["category"], "auth-revalidation")
        self.assertNotIn("password", result["safe"])
        self.assertNotIn("status", result["safe"])
        self.assertEqual(result["safe"]["payloadStatus"], "degraded")

    def test_json_diagnostics_timeout_preserves_base_5xx_evidence(self):
        result = self._run_diagnostics_js(
            """(async () => {
                const started = Date.now();
                const response = {
                    headers: () => ({'content-type': 'application/json'}),
                    json: () => new Promise(() => {})
                };
                const safe = await decodeServerErrorPayload(response, 25);
                return {safe, elapsedMs: Date.now() - started};
            })()"""
        )
        self.assertEqual(result["safe"], {})
        self.assertLess(result["elapsedMs"], 1000)

        source = SMOKE.read_text(encoding="utf-8")
        self.assertIn("httpStatus: response.status()", source)
        self.assertIn("payloadStatus", source)
        self.assertNotIn("status: response.status()", source)

    def test_diagnostics_tests_fail_on_unrelated_errors(self):
        with self.assertRaises(subprocess.CalledProcessError):
            self._run_diagnostics_js(
                """(async () => {
                    throw new TypeError('unrelated test failure');
                })()"""
            )

    def test_existing_functional_checks_remain_required(self):
        source = SMOKE.read_text(encoding="utf-8")
        self.assertIn("Production health/version/SHA/database does not match exact main.", source)
        self.assertIn("Production has no dated Event available for the #123 read-only verification.", source)
        self.assertIn("Production needs at least one published Artist and one published Event to verify #124", source)
        self.assertIn("#125 Dashboard transition", source)
        self.assertIn("if (evidence.blockedMutations.length)", source)


if __name__ == "__main__":
    unittest.main()
