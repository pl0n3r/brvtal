"""Regression: public health omits private schema details.

Factory checks exact release identity; BRVTAL emits status=ok only after
database and migration readiness pass.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/production-deploy-observer.yml"
HEALTH = ROOT / "api/health.php"
READINESS = ROOT / "config/deploy_readiness.php"
SMOKE = ROOT / "tests/e2e/production-authenticated-smoke.mjs"


class PrivateHealthObserverTests(unittest.TestCase):
    def test_workflow_contract(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        health = HEALTH.read_text(encoding="utf-8")
        readiness = READINESS.read_text(encoding="utf-8")
        self.assertIn("uses: pl0n3r/factory/.github/workflows/observar.yml@v1", workflow)
        self.assertIn("health_path: /api/health.php", workflow)
        self.assertIn("expected_sha: " + chr(36) + "{{ github.sha }}", workflow)
        self.assertIn("version_source: config/version.php", workflow)
        self.assertEqual(len(re.findall(r"^\s*require_schema:\s*false\s*$", workflow, re.M)), 1)
        self.assertNotRegex(workflow, r"(?m)^\s*require_schema:\s*true\s*$")
        self.assertIn("$pdo->query('SELECT 1')", health)
        self.assertIn("brvtal_migration_status($pdo", health)
        self.assertIn("brvtalFactoryReadinessState($exact, $resolvedSha, $migrationStatus)", health)
        self.assertIn("$statusCode = $readiness['ready'] ? 200 : 503;", health)
        self.assertIn("'status' => $readiness['status']", health)
        self.assertIn("'release_sha' => $readiness['release_sha']", health)
        self.assertIn("brvtalMigrationVerifyPlanStatus", readiness)
        self.assertIn("$ready = $safeSha !== null && $schemaUpToDate;", readiness)
        for sensitive in ("'database' =>", "'schema' =>", "'schema_up_to_date' =>"):
            self.assertNotIn(sensitive, health)

    def test_authenticated_smoke_uses_public_health_contract(self):
        smoke = SMOKE.read_text(encoding="utf-8")
        self.assertIn("health.ok === true", smoke)
        self.assertIn("health.status === 'ok'", smoke)
        self.assertIn("health.release_sha === expectedSha", smoke)
        self.assertIn("deployment.version === expectedVersion", smoke)
        self.assertIn("deployment.commit === expectedSha", smoke)
        self.assertIn("deployment.exact === true", smoke)
        self.assertNotIn("health.database", smoke)
        self.assertIn("blockedMutations", smoke)


if __name__ == "__main__":
    unittest.main()
