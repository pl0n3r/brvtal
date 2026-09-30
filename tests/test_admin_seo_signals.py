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

    def js_seo_view(self, payload):
        js = (ROOT / "discadmin" / "dashboard-v2.js").read_text(encoding="utf-8")
        start = js.index("  function developmentSeoView(summary) {")
        end = js.index("\n\n  function developmentSources", start)
        function_source = js[start:end].strip()
        script = (
            function_source
            + "\nconsole.log(JSON.stringify(developmentSeoView(JSON.parse(process.argv[1]))));"
        )
        result = subprocess.run(
            ["node", "-e", script, json.dumps(payload)],
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
        unavailable_payloads = [
            {
                "status": "available",
                "freshness": "fresh",
                "read_only": True,
                "total": None,
                "issues": None,
                "auto": None,
                "manual": None,
                "truncated_resources": [],
            },
            {
                "status": "available",
                "freshness": "fresh",
                "read_only": True,
                "total": "0",
                "issues": "0",
                "auto": "0",
                "manual": "0",
                "truncated_resources": [],
            },
            {
                "status": "unknown",
                "freshness": "fresh",
                "read_only": True,
                "total": 0,
                "issues": 0,
                "auto": 0,
                "manual": 0,
                "truncated_resources": [],
            },
            {
                "status": "available",
                "freshness": "fresh",
                "read_only": False,
                "total": 0,
                "issues": 0,
                "auto": 0,
                "manual": 0,
                "truncated_resources": [],
            },
            {
                "status": "available",
                "freshness": "fresh",
                "read_only": True,
                "total": 0,
                "issues": 0,
                "auto": 0,
                "manual": 0,
            },
            {
                "status": "available",
                "freshness": "fresh",
                "read_only": True,
                "total": 0,
                "issues": 0,
                "auto": 0,
                "manual": 0,
                "truncated_resources": "events",
            },
            {
                "status": "available",
                "freshness": "fresh",
                "read_only": True,
                "total": 0,
                "issues": 0,
                "auto": 0,
                "manual": 0,
                "truncated_resources": [None],
            },
            {
                "status": "available",
                "freshness": "fresh",
                "read_only": True,
                "total": 0,
                "issues": 0,
                "auto": 0,
                "manual": 0,
                "truncated_resources": ["events"],
            },
            {
                "status": "partial",
                "freshness": "stale",
                "read_only": True,
                "total": 0,
                "issues": 0,
                "auto": 0,
                "manual": 0,
                "truncated_resources": [],
            },
        ]
        for payload in unavailable_payloads:
            with self.subTest(payload=payload):
                view = self.js_seo_view(payload)
                self.assertEqual(view["state"], "UNAVAILABLE")
                self.assertEqual(view["freshness"], "unavailable")
                self.assertIsNone(view["total"])
                self.assertIsNone(view["issues"])

        clean = self.js_seo_view(
            {
                "status": "available",
                "freshness": "fresh",
                "read_only": True,
                "total": 4,
                "issues": 0,
                "auto": 3,
                "manual": 1,
                "truncated_resources": [],
            }
        )
        self.assertEqual((clean["state"], clean["freshness"], clean["total"]), ("CLEAN", "fresh", 4))

        partial = self.js_seo_view(
            {
                "status": "partial",
                "freshness": "stale",
                "read_only": True,
                "total": 4,
                "issues": 1,
                "auto": 3,
                "manual": 1,
                "truncated_resources": ["events"],
            }
        )
        self.assertEqual((partial["state"], partial["freshness"]), ("PARTIAL", "stale"))

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

    def test_deploy_bound_version_is_0_1_95(self):
        version = (ROOT / "config" / "version.php").read_text(encoding="utf-8")
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        self.assertIn("BRVTAL_APP_VERSION = '0.1.95'", version)
        self.assertEqual(package["version"], "0.1.95")


if __name__ == "__main__":
    unittest.main()
