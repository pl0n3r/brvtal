import json
import pathlib
import runpy
import unittest
from types import SimpleNamespace
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/production-migration-reconcile.yml"

class ProductionMigrationReconcileWorkflowTests(unittest.TestCase):
    def setUp(self):
        self.text = WORKFLOW.read_text(encoding="utf-8")

    def test_owner_only_exact_command_and_incident(self):
        self.assertIn("github.event.issue.number == 681", self.text)
        self.assertIn("github.event.comment.user.login == github.repository_owner", self.text)
        self.assertIn("github.event.comment.author_association == 'OWNER'", self.text)
        self.assertIn("github.event.comment.body == '/reconcile-production-migrations'", self.text)

    def test_uses_existing_fail_closed_transport(self):
        self.assertIn("python3 ops/factory/transport.py validate", self.text)
        self.assertIn("inspect-migrations", self.text)
        self.assertIn("reconcile-migrations", self.text)
        self.assertIn("Proven additive apply candidates", self.text)
        self.assertIn("Blocked / ambiguous candidates", self.text)
        self.assertIn('if plan["blocked"]:', self.text)
        self.assertIn("DEPLOY_TOKEN: ${{ secrets.DEPLOY_TOKEN }}", self.text)
        self.assertIn("DEPLOY_SSH_KEY: ${{ secrets.DEPLOY_SSH_KEY }}", self.text)
        self.assertNotIn("StrictHostKeyChecking=no", self.text)
        self.assertNotIn("BRVTAL_MIGRATIONS_ALLOW_WRITE", self.text)

    def test_exact_main_and_bounded_permissions(self):
        self.assertIn("ref: main", self.text)
        self.assertIn("persist-credentials: false", self.text)
        self.assertIn("contents: read", self.text)
        self.assertIn("actions: write", self.text)
        self.assertNotIn("issues: write", self.text)
        self.assertIn("timeout-minutes: 15", self.text)
        self.assertIn("cancel-in-progress: false", self.text)

    def test_success_dispatches_existing_authenticated_smoke(self):
        self.assertIn("gh workflow run production-authenticated-smoke.yml --ref main", self.text)
        self.assertLess(self.text.index("reconcile-migrations"), self.text.index("gh workflow run production-authenticated-smoke.yml"))

    def test_empty_reconciled_plan_normalizes_php_empty_proofs_array(self):
        namespace = runpy.run_path(str(ROOT / "ops/factory/transport.py"))
        sanitize = namespace["_sanitize_reconciliation_plan"]
        plan = sanitize({
            "registry_exists": True,
            "record_registry_migration": False,
            "pending": [],
            "baseline": [],
            "apply": [],
            "blocked": [],
            "actions": [],
            "proofs": [],
        })
        self.assertEqual(plan["proofs"], {})

    def test_empty_proofs_array_still_fails_closed_with_pending_migration(self):
        namespace = runpy.run_path(str(ROOT / "ops/factory/transport.py"))
        sanitize = namespace["_sanitize_reconciliation_plan"]
        error = namespace["TransportError"]
        payload = {
            "registry_exists": True,
            "record_registry_migration": False,
            "pending": ["migration_blog_01.sql"],
            "baseline": ["migration_blog_01.sql"],
            "apply": [],
            "blocked": [],
            "actions": [{"migration": "migration_blog_01.sql", "action": "baseline"}],
            "proofs": [],
        }
        with self.assertRaisesRegex(error, "proofs are invalid"):
            sanitize(payload)

    def test_readiness_public_contract_without_private_schema(self):
        """Exact HTTPS health remains fail-closed without leaking migration metadata."""
        namespace = runpy.run_path(str(ROOT / "ops/factory/transport.py"))
        check = namespace["assert_factory_readiness"]
        error = namespace["TransportError"]
        marker = namespace["_FACTORY_READINESS_MARKER"]
        sha = "a" * 40
        version = "0.1.252"
        origin = "https://www.brvtal.com.co"
        healthy = {
            "ok": True,
            "app": "BRVTAL",
            "status": "ok",
            "version": version,
            "release_sha": sha,
            "deployment": {
                "commit": sha, "source": "git_checkout",
                "exact": True, "version": version,
            },
        }
        self.assertNotIn("schema_up_to_date", healthy)

        def curl_result(payload, http_status="200", content_type="application/json; charset=utf-8", returncode=0):
            body = payload if isinstance(payload, str) else json.dumps(payload)
            return SimpleNamespace(
                returncode=returncode,
                stdout=body + marker + http_status + "\t" + content_type,
                stderr="",
            )

        with mock.patch("subprocess.run", return_value=curl_result(healthy)) as curl:
            check(origin, sha, version)
            command = curl.call_args.args[0]
            self.assertIn("--proto", command)
            self.assertEqual(command[command.index("--proto") + 1], "=https")
            self.assertIn("--max-filesize", command)
            self.assertIn("Accept: application/json", command)

        cases = [
            ("HTTP 503", healthy, "503", "application/json", 0),
            ("HTML response", healthy, "200", "text/html", 0),
            ("invalid JSON", "{not-json", "200", "application/json", 0),
            ("not ready", {**healthy, "ok": False}, "200", "application/json", 0),
            ("wrong status", {**healthy, "status": "degraded"}, "200", "application/json", 0),
            ("wrong version", {**healthy, "version": "0.1.251"}, "200", "application/json", 0),
            ("wrong release SHA", {**healthy, "release_sha": "b" * 40}, "200", "application/json", 0),
            ("missing deployment", {key: value for key, value in healthy.items() if key != "deployment"}, "200", "application/json", 0),
            ("invalid exact flag", {**healthy, "deployment": {**healthy["deployment"], "exact": False}}, "200", "application/json", 0),
            ("wrong deployment SHA", {**healthy, "deployment": {**healthy["deployment"], "commit": "b" * 40}}, "200", "application/json", 0),
            ("wrong deployment version", {**healthy, "deployment": {**healthy["deployment"], "version": "0.1.251"}}, "200", "application/json", 0),
            ("fallback source", {**healthy, "deployment": {**healthy["deployment"], "source": "release_fallback"}}, "200", "application/json", 0),
            ("curl transport error", healthy, "200", "application/json", 7),
        ]
        for name, payload, status, mime, code in cases:
            with self.subTest(case=name), mock.patch(
                "subprocess.run",
                return_value=curl_result(payload, status, mime, code),
            ), self.assertRaises(error):
                check(origin, sha, version)

if __name__ == "__main__":
    unittest.main()
