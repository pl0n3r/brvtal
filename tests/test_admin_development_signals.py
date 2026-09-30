import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class AdminDevelopmentSignalsTests(unittest.TestCase):
    def php_fixture(self, fail=False, empty=False):
        config = (ROOT / "config" / "admin_development_signals.php").as_posix()
        script = f"""
require {json.dumps(config)};
$fail = {'true' if fail else 'false'};
$empty = {'true' if empty else 'false'};
$fn = function(string $url) use ($fail,$empty): array {{
  if ($fail) throw new RuntimeException('offline');
  if (str_contains($url, 'is%3Aissue')) return ['status'=>200,'body'=>json_encode(['total_count'=>$empty?0:7,'items'=>[]])];
  if (str_contains($url, 'is%3Apr')) return ['status'=>200,'body'=>json_encode(['total_count'=>$empty?0:2,'items'=>$empty?[]:[['number'=>123,'title'=>'Candidate','html_url'=>'https://github.com/pl0n3r/brvtal/pull/123']]])];
  if (!str_contains($url, '/repos/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/runs?')) throw new RuntimeException('invalid actions route');
  return ['status'=>200,'body'=>json_encode(['workflow_runs'=>[['status'=>'completed','conclusion'=>'success','html_url'=>'https://github.com/pl0n3r/brvtal/actions/runs/1','updated_at'=>gmdate('c')]]])];
}};
echo json_encode(brvtalAdminDevelopmentSignals($fn));
"""
        result = subprocess.run(["php", "-r", script], cwd=ROOT, check=True, text=True, capture_output=True)
        return json.loads(result.stdout)

    def test_normalizes_open_work_and_latest_ci_without_credentials(self):
        data = self.php_fixture()
        self.assertEqual((data["open_issues"], data["open_prs"]), (7, 2))
        self.assertEqual(data["latest_pr"]["number"], 123)
        self.assertEqual(data["latest_ci"]["conclusion"], "success")
        self.assertNotIn("token", json.dumps(data).lower())
        self.assertTrue(data["read_only"])

    def test_external_failure_is_unavailable_not_fake_healthy(self):
        data = self.php_fixture(fail=True)
        self.assertEqual(data["status"], "unavailable")
        self.assertEqual(data["freshness"], "unavailable")
        self.assertIsNone(data["open_issues"])
        self.assertIsNone(data["open_prs"])

    def test_dashboard_exposes_configurable_read_only_development_module(self):
        catalog = (ROOT / "config" / "admin_dashboard.php").read_text()
        js = (ROOT / "discadmin" / "dashboard-v2.js").read_text()
        self.assertIn("'development' =>", catalog)
        self.assertIn("development:'/api/admin-development-signals.php'", js)
        self.assertIn("development:developmentPanel", js)

    def test_no_client_secret_or_github_mutation_surface(self):
        api = (ROOT / "api" / "admin-development-signals.php").read_text().lower()
        js = (ROOT / "discadmin" / "dashboard-v2.js").read_text().lower()
        config = (ROOT / "config" / "admin_development_signals.php").read_text().lower()
        self.assertNotIn("github_token", js)
        self.assertNotIn("authorization:", js)
        for verb in ("post", "patch", "delete"):
            self.assertNotIn(f"method:'{verb}'", api)
        self.assertNotIn("api.github.com", api)
        self.assertIn("brvtal_github_token", config)

    def test_dashboard_module_keeps_accessible_responsive_contract(self):
        js = (ROOT / "discadmin" / "dashboard-v2.js").read_text()
        css = (ROOT / "discadmin" / "dashboard-v2.css").read_text()
        self.assertIn('rel="noopener noreferrer"', js)
        self.assertIn('target="_blank"', js)
        self.assertIn(".dashboard-v2-development-grid", css)
        self.assertIn("@media(max-width:440px)", css)

    def test_success_empty_and_failure_fixtures_are_deterministic(self):
        self.assertEqual(self.php_fixture(empty=True)["open_prs"], 0)
        self.assertIsNone(self.php_fixture(empty=True)["latest_pr"])
        self.assertEqual(self.php_fixture(fail=True)["status"], "unavailable")

    def test_deploy_bound_version_is_0_1_89(self):
        version = (ROOT / "config" / "version.php").read_text()
        package = json.loads((ROOT / "package.json").read_text())
        self.assertIn("BRVTAL_APP_VERSION = '0.1.89'", version)
        self.assertEqual(package["version"], "0.1.89")


if __name__ == "__main__":
    unittest.main()
