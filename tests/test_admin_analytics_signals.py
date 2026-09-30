import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class AdminAnalyticsSignalsTests(unittest.TestCase):
    def fixture(self, mode="fresh"):
        config = (ROOT / "config" / "admin_analytics_signals.php").as_posix()
        script = f"""
require {json.dumps(config)};
$mode = {json.dumps(mode)};
$now = 1790769600;
$config = $mode === 'not_configured'
    ? ['property_id'=>'', 'client_email'=>'', 'private_key'=>'']
    : [
        'property_id'=>'123456789',
        'client_email'=>'readonly@example.test',
        'private_key'=>"-----BEGIN PRIVATE KEY-----\\nTEST\\n-----END PRIVATE KEY-----",
    ];
$cachedData = [
    'status'=>'available',
    'freshness'=>'fresh',
    'state'=>'FRESH',
    'metrics'=>['users'=>8,'sessions'=>9,'views'=>10],
    'previous'=>['users'=>7,'sessions'=>8,'views'=>9],
    'top_pages'=>[['path'=>'/cached','views'=>5]],
    'devices'=>[['category'=>'mobile','users'=>8]],
    'source_at'=>'2026-09-30T10:00:00+00:00',
    'cached'=>false,
    'read_only'=>true,
];
$cache = null;
if ($mode === 'fresh_cache') {{
    $cache = ['property_id'=>'123456789','stored_at'=>$now - 60,'data'=>$cachedData];
}} elseif ($mode === 'stale_cache') {{
    $cache = ['property_id'=>'123456789','stored_at'=>$now - 600,'data'=>$cachedData];
}}
$cacheReader = static fn() => $cache;
$cacheWrites = [];
$cacheWriter = function(array $record) use (&$cacheWrites): void {{ $cacheWrites[] = $record; }};
$clock = static fn(): int => $now;
$assertion = static fn(array $cfg, int $iat): string => 'signed.jwt.assertion';
$request = function(string $method, string $url, array $headers, string $body) use ($mode): array {{
    if ($mode === 'api_failure' || $mode === 'stale_cache') throw new RuntimeException('offline');
    if ($url === BRVTAL_GA4_TOKEN_URL) {{
        return ['status'=>200,'body'=>json_encode(['access_token'=>'ephemeral-access-token','token_type'=>'Bearer'])];
    }}
    if ($mode === 'invalid_payload') return ['status'=>200,'body'=>'{{}}'];
    $decoded = json_decode($body, true);
    $dimension = $decoded['dimensions'][0]['name'] ?? '';
    $range = $decoded['dateRanges'][0]['startDate'] ?? '';
    if ($dimension === 'pagePath') {{
        return ['status'=>200,'body'=>json_encode(['rows'=>[
            ['dimensionValues'=>[['value'=>'/events']], 'metricValues'=>[['value'=>'40']]],
            ['dimensionValues'=>[['value'=>'/artists']], 'metricValues'=>[['value'=>'20']]],
        ]])];
    }}
    if ($dimension === 'deviceCategory') {{
        return ['status'=>200,'body'=>json_encode(['rows'=>[
            ['dimensionValues'=>[['value'=>'mobile']], 'metricValues'=>[['value'=>'12']]],
            ['dimensionValues'=>[['value'=>'desktop']], 'metricValues'=>[['value'=>'6']]],
        ]])];
    }}
    $values = $range === '14daysAgo' ? ['10','15','20'] : ['12','18','30'];
    return ['status'=>200,'body'=>json_encode(['rows'=>[['metricValues'=>[
        ['value'=>$values[0]],['value'=>$values[1]],['value'=>$values[2]]
    ]]])])];
}};
$data = brvtalAdminAnalyticsSignals(
    $request,
    $cacheReader,
    $cacheWriter,
    $clock,
    $assertion,
    $config
);
echo json_encode(['data'=>$data,'cache_writes'=>$cacheWrites], JSON_THROW_ON_ERROR);
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

    def test_not_configured_is_explicit_and_secret_safe(self):
        result = self.fixture("not_configured")
        data = result["data"]
        self.assertEqual((data["state"], data["status"], data["freshness"]), (
            "NOT CONFIGURED", "not_configured", "not_configured"
        ))
        self.assertEqual(data["metrics"], {"users": None, "sessions": None, "views": None})
        encoded = json.dumps(data).lower()
        for secret_name in ("private_key", "client_email", "access_token", "assertion"):
            self.assertNotIn(secret_name, encoded)

    def test_mocked_ga4_summary_is_normalized_and_bounded(self):
        result = self.fixture("fresh")
        data = result["data"]
        self.assertEqual((data["state"], data["freshness"]), ("FRESH", "fresh"))
        self.assertEqual(data["metrics"], {"users": 12, "sessions": 18, "views": 30})
        self.assertEqual(data["previous"], {"users": 10, "sessions": 15, "views": 20})
        self.assertEqual(data["top_pages"][0], {"path": "/events", "views": 40})
        self.assertEqual(data["devices"][0], {"category": "mobile", "users": 12})
        self.assertLessEqual(len(data["top_pages"]), 5)
        self.assertLessEqual(len(data["devices"]), 5)
        self.assertTrue(data["read_only"])
        self.assertEqual(len(result["cache_writes"]), 1)
        self.assertNotIn("ephemeral-access-token", json.dumps(result))

    def test_failures_are_unavailable_and_valid_cache_can_be_stale(self):
        for mode in ("api_failure", "invalid_payload"):
            with self.subTest(mode=mode):
                data = self.fixture(mode)["data"]
                self.assertEqual((data["state"], data["freshness"]), ("UNAVAILABLE", "unavailable"))
                self.assertIsNone(data["metrics"]["users"])
        stale = self.fixture("stale_cache")["data"]
        self.assertEqual((stale["state"], stale["freshness"], stale["cached"]), ("STALE", "stale", True))
        self.assertEqual(stale["metrics"]["views"], 10)
        fresh_cache = self.fixture("fresh_cache")["data"]
        self.assertEqual((fresh_cache["state"], fresh_cache["freshness"], fresh_cache["cached"]), ("FRESH", "fresh", True))

    def test_endpoint_is_authenticated_get_only_no_store_and_secret_safe(self):
        api = (ROOT / "api" / "admin-analytics-signals.php").read_text(encoding="utf-8")
        config = (ROOT / "config" / "admin_analytics_signals.php").read_text(encoding="utf-8")
        self.assertIn("brvtal_admin_require();", api)
        self.assertIn("METHOD_NOT_ALLOWED", api)
        self.assertIn("'Allow'=>'GET'", api)
        self.assertIn("'Cache-Control'=>'no-store'", api)
        self.assertIn("CURLOPT_FOLLOWLOCATION=>false", config)
        self.assertIn("analytics.readonly", config)
        self.assertIn("GA4_URL_NOT_ALLOWED", config)
        self.assertNotIn("private_key'=>$config", config)
        self.assertNotIn("client_email'=>$config", config)

    def test_dashboard_renders_analytics_independently(self):
        js = (ROOT / "discadmin" / "dashboard-v2.js").read_text(encoding="utf-8")
        self.assertIn("analytics:'/api/admin-analytics-signals.php'", js)
        self.assertIn("function analyticsView(analytics)", js)
        self.assertIn("function redrawAnalytics(results, serial)", js)
        self.assertIn("hydrateAnalyticsSignal(results,serial)", js)
        self.assertIn("analytics:analyticsPanel(resultValue(results[12]),resultError(results[12]))", js)
        self.assertIn("data-testid=\\"dashboard-analytics-state\\"", js)
        self.assertIn("{id:'analytics',width:2,height:1,visible:true}", js)
        self.assertIn("hydrateDevelopmentSource(results,serial,11,ENDPOINTS.backup)", js)

    def test_deploy_bound_version_is_0_1_98(self):
        version = (ROOT / "config" / "version.php").read_text(encoding="utf-8")
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        self.assertIn("BRVTAL_APP_VERSION = '0.1.98'", version)
        self.assertEqual(package["version"], "0.1.98")


if __name__ == "__main__":
    unittest.main()
