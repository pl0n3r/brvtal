import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class AdminSonarSignalsTests(unittest.TestCase):
    def fixture(self, mode="passed"):
        config = (ROOT / "config" / "admin_sonar_signals.php").as_posix()
        script = f"""
require {json.dumps(config)};
$mode = {json.dumps(mode)};
$request = function(string $url) use ($mode): array {{
    if ($mode === 'failure') throw new RuntimeException('offline');
    if (str_contains($url, '/qualitygates/project_status?')) {{
        if ($mode === 'incomplete') return ['status'=>200,'body'=>json_encode(['projectStatus'=>[]])];
        $status = $mode === 'failed' ? 'ERROR' : 'OK';
        return ['status'=>200,'body'=>json_encode(['projectStatus'=>['status'=>$status]])];
    }}
    if (str_contains($url, '/issues/search?')) {{
        return ['status'=>200,'body'=>json_encode(['paging'=>['total'=>2]])];
    }}
    if (str_contains($url, '/hotspots/search?')) {{
        return ['status'=>200,'body'=>json_encode(['paging'=>['total'=>1]])];
    }}
    if (str_contains($url, '/project_analyses/search?')) {{
        $date = $mode === 'stale' ? '2020-01-01T00:00:00+0000' : gmdate('c');
        return ['status'=>200,'body'=>json_encode(['analyses'=>[['date'=>$date]]])];
    }}
    throw new RuntimeException('unexpected_url');
}};
echo json_encode(brvtalAdminSonarSignals($request), JSON_THROW_ON_ERROR);
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

    def test_normalizes_readonly_sonar_quality_gate_without_credentials(self):
        data = self.fixture("passed")
        self.assertEqual(data["status"], "available")
        self.assertEqual(data["quality_gate"], "passed")
        self.assertEqual((data["new_issues"], data["security_hotspots"]), (2, 1))
        self.assertEqual(data["freshness"], "fresh")
        self.assertTrue(data["read_only"])
        self.assertNotIn("token", json.dumps(data).lower())
        self.assertTrue(data["dashboard_url"].startswith("https://sonarcloud.io/"))

        failed = self.fixture("failed")
        self.assertEqual(failed["quality_gate"], "failed")

    def test_external_failure_or_incomplete_payload_is_unavailable_not_fake_healthy(self):
        for mode in ("failure", "incomplete"):
            data = self.fixture(mode)
            self.assertEqual(data["status"], "unavailable")
            self.assertEqual(data["freshness"], "unavailable")
            self.assertIsNone(data["quality_gate"])
            self.assertIsNone(data["new_issues"])
            self.assertIsNone(data["security_hotspots"])
            self.assertIsNone(data["source_at"])

    def test_dashboard_development_module_renders_sonar_independently(self):
        js = (ROOT / "discadmin" / "dashboard-v2.js").read_text(encoding="utf-8")
        self.assertIn("sonar:'/api/admin-sonar-signals.php'", js)
        self.assertIn("SONAR GATE", js)
        self.assertIn("SONAR NEW ISSUES", js)
        self.assertIn("SONAR HOTSPOTS", js)
        self.assertIn("hydrateDevelopmentSource(results,serial,7,ENDPOINTS.sonar)", js)

    def test_no_client_secret_or_sonar_mutation_surface(self):
        api = (ROOT / "api" / "admin-sonar-signals.php").read_text(encoding="utf-8").lower()
        config = (ROOT / "config" / "admin_sonar_signals.php").read_text(encoding="utf-8").lower()
        js = (ROOT / "discadmin" / "dashboard-v2.js").read_text(encoding="utf-8").lower()
        self.assertNotIn("brvtal_sonar_token", js)
        self.assertNotIn("authorization:", js)
        self.assertIn("brvtal_sonar_token", config)
        self.assertIn("str_starts_with($url, brvtal_sonar_base . '/api/')", config)
        for verb in ("post", "patch", "delete"):
            self.assertNotIn(f"method:'{verb}'", api)
            self.assertNotIn(f'method:"{verb}"', js)

    def test_sonar_links_are_allowlisted_and_safe(self):
        js = (ROOT / "discadmin" / "dashboard-v2.js").read_text(encoding="utf-8")
        self.assertIn("https:\\/\\/sonarcloud\\.io\\/", js)
        self.assertIn('target="_blank"', js)
        self.assertIn('rel="noopener noreferrer"', js)

    def test_success_failure_stale_and_incomplete_fixtures_are_deterministic(self):
        self.assertEqual(self.fixture("passed")["quality_gate"], "passed")
        self.assertEqual(self.fixture("failed")["quality_gate"], "failed")
        self.assertEqual(self.fixture("stale")["freshness"], "stale")
        self.assertEqual(self.fixture("incomplete")["status"], "unavailable")

    def test_deploy_bound_version_is_0_1_90(self):
        version = (ROOT / "config" / "version.php").read_text(encoding="utf-8")
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        self.assertIn("BRVTAL_APP_VERSION = '0.1.90'", version)
        self.assertEqual(package["version"], "0.1.90")


if __name__ == "__main__":
    unittest.main()
