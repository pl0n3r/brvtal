import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class AdminSeoSignalsTests(unittest.TestCase):
    def php_summary(self, mode="clean"):
        config = (ROOT / "config" / "seo_workspace.php").as_posix()
        script = f"""
require {json.dumps(config)};
$mode = {json.dumps(mode)};
$items = [];
$truncated = [];
if ($mode === 'clean') {{
    $items = [
        ['mode'=>'AUTO','warnings'=>[]],
        ['mode'=>'MANUAL','warnings'=>[]],
    ];
}} elseif ($mode === 'warnings') {{
    $items = [
        ['mode'=>'AUTO','warnings'=>['MISSING_TITLE','DESCRIPTION_LONG']],
        ['mode'=>'MIXED','warnings'=>['MISSING_TITLE']],
    ];
}} elseif ($mode === 'truncated') {{
    $items = [['mode'=>'AUTO','warnings'=>[]]];
    $truncated = ['events'];
}}
echo json_encode(
    brvtalSeoWorkspaceSummary($items, $truncated),
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

    def test_summary_reuses_canonical_warning_contract(self):
        config = (ROOT / "config" / "seo_workspace.php").read_text(encoding="utf-8")
        api = (ROOT / "api" / "seo-workspace.php").read_text(encoding="utf-8")
        self.assertIn("function brvtalSeoWorkspaceWarnings(", config)
        self.assertIn("function brvtalSeoWorkspaceSummary(", config)
        self.assertIn("$item['warnings'] = brvtalSeoWorkspaceWarnings($item);", api)
        self.assertIn("brvtalSeoWorkspaceSummary($items, $truncatedResources)", api)
        summary = self.php_summary("warnings")
        self.assertEqual(summary["issues"], 2)
        self.assertEqual(summary["warning_counts"]["MISSING_TITLE"], 2)
        self.assertEqual(summary["warning_counts"]["DESCRIPTION_LONG"], 1)

    def test_summary_mode_is_compact_and_full_get_remains_compatible(self):
        api = (ROOT / "api" / "seo-workspace.php").read_text(encoding="utf-8")
        self.assertIn("($_GET['summary'] ?? '') === '1'", api)
        self.assertIn("brvtalSeoWorkspaceJson(['ok'=>true,'data'=>$summary]);", api)
        self.assertIn("brvtalSeoWorkspaceJson(['ok'=>true,'data'=>$items,'summary'=>$summary]);", api)

    def test_truncated_inventory_is_explicitly_partial(self):
        summary = self.php_summary("truncated")
        self.assertEqual(summary["status"], "partial")
        self.assertEqual(summary["freshness"], "stale")
        self.assertEqual(summary["truncated_resources"], ["events"])
        self.assertTrue(summary["read_only"])

    def test_dashboard_renders_independent_seo_health_and_workspace_action(self):
        js = (ROOT / "discadmin" / "dashboard-v2.js").read_text(encoding="utf-8")
        self.assertIn("seo:'/api/seo-workspace.php?summary=1'", js)
        self.assertIn("SEO HEALTH", js)
        self.assertIn("SEO INVENTORY", js)
        self.assertIn("SEO AUTO", js)
        self.assertIn("SEO MANUAL", js)
        self.assertIn("/discadmin/?module=seo", js)
        self.assertIn("hydrateDevelopmentSource(results,serial,10,ENDPOINTS.seo)", js)

    def test_seo_failure_is_unavailable_not_fake_zero(self):
        js = (ROOT / "discadmin" / "dashboard-v2.js").read_text(encoding="utf-8")
        self.assertIn("state:'UNAVAILABLE'", js)
        self.assertIn("total:null", js)
        self.assertIn("issues:null", js)
        self.assertIn("summary payload incomplete", js)
        self.assertNotIn("SEO HEALTH</span><b>0", js)

    def test_no_mutation_score_secret_external_analytics_or_schema_change(self):
        changed = "\n".join(
            (ROOT / path).read_text(encoding="utf-8")
            for path in (
                "config/seo_workspace.php",
                "api/seo-workspace.php",
                "discadmin/dashboard-v2.js",
            )
        ).lower()
        self.assertNotIn("seo_score", changed)
        self.assertNotIn("search console", changed)
        self.assertNotIn("google analytics", changed)
        self.assertNotIn("sonar_token", changed)
        self.assertNotIn("github_token", changed)
        self.assertNotIn("create table", changed)
        self.assertNotIn("alter table", changed)

    def test_summary_fixtures_are_deterministic(self):
        clean = self.php_summary("clean")
        warning = self.php_summary("warnings")
        partial = self.php_summary("truncated")

        self.assertEqual(
            (clean["status"], clean["freshness"], clean["healthy"], clean["issues"]),
            ("available", "fresh", 2, 0),
        )
        self.assertEqual(
            (warning["healthy"], warning["issues"], warning["auto"], warning["manual"]),
            (0, 2, 1, 1),
        )
        self.assertEqual((partial["status"], partial["freshness"]), ("partial", "stale"))

    def test_deploy_bound_version_is_0_1_94(self):
        version = (ROOT / "config" / "version.php").read_text(encoding="utf-8")
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        self.assertIn("BRVTAL_APP_VERSION = '0.1.94'", version)
        self.assertEqual(package["version"], "0.1.94")


if __name__ == "__main__":
    unittest.main()
