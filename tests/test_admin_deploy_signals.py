import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class AdminDeploySignalsTests(unittest.TestCase):
    def fixture(self, mode="exact_ready"):
        config = (ROOT / "config" / "admin_deploy_signals.php").as_posix()
        script = f"""
require {json.dumps(config)};
$mode = {json.dumps(mode)};
$deployment = function() use ($mode): array {{
    if ($mode === 'identity_failure') throw new RuntimeException('identity unavailable');
    $exact = $mode !== 'fallback';
    return [
        'commit'=>$exact ? str_repeat('a', 40) : null,
        'short_commit'=>$exact ? str_repeat('a', 7) : null,
        'source'=>$exact ? 'environment' : 'release_fallback',
        'exact'=>$exact,
        'version'=>'0.1.93',
        'release_identity'=>'v0.1.93',
        'cache_key'=>$exact ? str_repeat('a', 7) : 'release-0.1.93',
        'environment'=>'PRODUCTION',
        'release_date'=>'2026-09-30',
    ];
}};
$migrations = function() use ($mode): array {{
    if ($mode === 'db_failure') throw new RuntimeException('db unavailable');
    $state = $mode === 'degraded_schema' ? 'pending' : 'applied';
    return [
        'registry_exists'=>true,
        'migrations'=>[[
            'migration'=>'migration_example_01.sql',
            'state'=>$state,
            'checksum_sha256'=>str_repeat('b', 64),
        ]],
        'orphaned_records'=>[],
    ];
}};
echo json_encode(
    brvtalAdminDeploySignals($deployment, $migrations),
    JSON_THROW_ON_ERROR
);
"""
        result = subprocess.run(
            ["php", "-r", script],
            cwd=ROOT,
            check=True,
            text=True,
            capture_output=True,
            timeout=30,
        )
        return json.loads(result.stdout)

    def test_normalizes_exact_local_deploy_identity(self):
        data = self.fixture("exact_ready")
        self.assertEqual(data["status"], "available")
        self.assertEqual(data["version"], "0.1.93")
        self.assertTrue(data["exact"])
        self.assertEqual(data["release_sha"], "a" * 40)
        self.assertEqual(data["short_sha"], "a" * 7)
        self.assertEqual(data["source"], "environment")
        self.assertEqual(data["readiness"], "ready")
        self.assertTrue(data["schema_up_to_date"])
        self.assertTrue(data["read_only"])

    def test_reuses_canonical_readiness_without_mutation(self):
        config = (ROOT / "config" / "admin_deploy_signals.php").read_text(encoding="utf-8")
        self.assertIn("brvtalDeploymentPublicData()", config)
        self.assertIn("brvtal_migration_status(", config)
        self.assertIn("brvtalFactoryReadinessState(", config)
        self.assertIn("brvtalMigrationReadinessSummary(", config)
        for mutation in ("migration_apply", "INSERT INTO", "UPDATE ", "DELETE "):
            self.assertNotIn(mutation, config)

    def test_failures_degrade_without_fake_ready_state(self):
        schema = self.fixture("degraded_schema")
        self.assertEqual(schema["status"], "available")
        self.assertEqual(schema["readiness"], "degraded")
        self.assertFalse(schema["schema_up_to_date"])
        self.assertEqual(schema["schema"]["pending"], 1)

        db = self.fixture("db_failure")
        self.assertEqual(db["status"], "degraded")
        self.assertEqual(db["readiness"], "degraded")
        self.assertFalse(db["schema_up_to_date"])
        self.assertIsNone(db["schema"])

        identity = self.fixture("identity_failure")
        self.assertEqual(identity["status"], "unavailable")
        self.assertEqual(identity["readiness"], "degraded")
        self.assertIsNone(identity["release_sha"])

    def test_dashboard_renders_deploy_identity_independently(self):
        js = (ROOT / "discadmin" / "dashboard-v2.js").read_text(encoding="utf-8")
        self.assertIn("deploy:'/api/admin-deploy-signals.php'", js)
        self.assertIn("PROD VERSION", js)
        self.assertIn("PROD SHA", js)
        self.assertIn("READINESS", js)
        self.assertIn("SCHEMA", js)
        self.assertIn("hydrateDevelopmentSource(results,serial,9,ENDPOINTS.deploy)", js)

    def test_endpoint_is_authenticated_get_only_and_no_store(self):
        api = (ROOT / "api" / "admin-deploy-signals.php").read_text(encoding="utf-8")
        lowered = api.lower()
        self.assertIn("brvtal_admin_require();", api)
        self.assertIn("METHOD_NOT_ALLOWED", api)
        self.assertIn("'Allow'=>'GET'", api)
        self.assertIn("'Cache-Control'=>'no-store'", api)
        for secret in ("password", "token", "authorization", "private_key"):
            self.assertNotIn(secret, lowered)

    def test_exact_fallback_degraded_and_failure_fixtures_are_deterministic(self):
        exact = self.fixture("exact_ready")
        fallback = self.fixture("fallback")
        degraded = self.fixture("degraded_schema")
        failed = self.fixture("db_failure")

        self.assertEqual((exact["status"], exact["readiness"]), ("available", "ready"))
        self.assertEqual((fallback["exact"], fallback["release_sha"]), (False, None))
        self.assertEqual(fallback["readiness"], "degraded")
        self.assertEqual(degraded["schema"]["pending"], 1)
        self.assertEqual(failed["status"], "degraded")

    def test_deploy_bound_version_is_0_1_93(self):
        version = (ROOT / "config" / "version.php").read_text(encoding="utf-8")
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        self.assertIn("BRVTAL_APP_VERSION = '0.1.93'", version)
        self.assertEqual(package["version"], "0.1.93")


if __name__ == "__main__":
    unittest.main()
