import pathlib
import runpy
import unittest

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

if __name__ == "__main__":
    unittest.main()
