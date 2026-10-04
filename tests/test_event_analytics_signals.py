import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class EventAnalyticsSignalsTests(unittest.TestCase):
    def fixture(self, mode="fresh"):
        config = (ROOT / "config" / "event_analytics_signals.php").as_posix()
        script = f"""
require {json.dumps(config)};
$mode = {json.dumps(mode)};
$now = 1790769600;
$event = ['id'=>42, 'slug'=>'brvtal-night', 'status'=>'published'];
$claimed = '/events/brvtal-night';
if ($mode === 'draft') {{
    $event['status'] = 'draft';
}} elseif ($mode === 'private') {{
    $event['status'] = 'private';
}} elseif ($mode === 'unknown') {{
    $event = ['id'=>0, 'slug'=>'missing-event', 'status'=>'published'];
}} elseif ($mode === 'mismatch') {{
    $claimed = '/events/another-night';
}}
$configOverride = $mode === 'not_configured'
    ? ['property_id'=>'', 'client_email'=>'', 'private_key'=>'']
    : [
        'property_id'=>'123456789',
        'client_email'=>'readonly@example.test',
        'private_key'=>"-----BEGIN PRIVATE KEY-----\\nTEST\\n-----END PRIVATE KEY-----",
    ];
$cachedData = [
    'metrics'=>['users'=>8, 'sessions'=>9, 'views'=>10],
    'previous'=>['users'=>7, 'sessions'=>8, 'views'=>9],
    'source_at'=>'2026-09-30T10:00:00+00:00',
];
$cache = null;
if ($mode === 'stale_cache') {{
    $cache = [
        'property_id'=>'123456789',
        'event_id'=>42,
        'canonical_path'=>'/events/brvtal-night',
        'window_key'=>'7d',
        'stored_at'=>$now - 600,
        'data'=>$cachedData,
    ];
}}
$cacheReader = static fn() => $cache;
$cacheWrites = [];
$cacheWriter = function(array $record) use (&$cacheWrites): void {{ $cacheWrites[] = $record; }};
$clock = static fn(): int => $now;
$assertion = static fn(array $cfg, int $iat): string => 'signed.jwt.assertion';
$requests = [];
$requestCount = 0;
$request = function(string $method, string $url, array $headers, string $body) use ($mode, &$requests, &$requestCount): array {{
    $requestCount++;
    if ($mode === 'api_failure' || $mode === 'stale_cache') throw new RuntimeException('offline');
    if ($url === BRVTAL_GA4_TOKEN_URL) {{
        return ['status'=>200, 'body'=>json_encode(['access_token'=>'ephemeral-access-token'])];
    }}
    $decoded = json_decode($body, true);
    $requests[] = $decoded;
    if ($mode === 'invalid_payload') return ['status'=>200, 'body'=>'{{}}'];
    $start = $decoded['dateRanges'][0]['startDate'] ?? '';
    $values = $start === '14daysAgo' ? ['10', '15', '20'] : ['12', '18', '30'];
    return ['status'=>200, 'body'=>json_encode(['rows'=>[['metricValues'=>[
        ['value'=>$values[0]], ['value'=>$values[1]], ['value'=>$values[2]]
    ]]])])];
}};
$data = brvtalEventAnalyticsSignals(
    $event,
    $claimed,
    $request,
    $cacheReader,
    $cacheWriter,
    $clock,
    $assertion,
    $configOverride
);
echo json_encode([
    'data'=>$data,
    'requests'=>$requests,
    'request_count'=>$requestCount,
    'cache_writes'=>$cacheWrites,
], JSON_THROW_ON_ERROR);
"""
        result = subprocess.run(
            ["php", "-r", script],
            cwd=ROOT,
            check=False,
            text=True,
            capture_output=True,
            timeout=30,
        )
        if result.returncode != 0:
            self.fail(
                f"PHP fixture failed for mode={mode!r} with exit {result.returncode}:\n"
                f"{result.stderr.strip()}"
            )
        return json.loads(result.stdout)

    def test_published_event_uses_canonical_route_and_returns_bounded_aggregate_metrics_only(self):
        result = self.fixture("fresh")
        data = result["data"]
        self.assertEqual((data["state"], data["freshness"]), ("FRESH", "fresh"))
        self.assertEqual(data["event_id"], 42)
        self.assertEqual(data["canonical_path"], "/events/brvtal-night")
        self.assertEqual(data["window"], {"key": "7d", "start": "7daysAgo", "end": "yesterday"})
        self.assertEqual(data["metrics"], {"users": 12, "sessions": 18, "views": 30})
        self.assertEqual(data["previous"], {"users": 10, "sessions": 15, "views": 20})
        self.assertEqual(data["source"], "ga4")
        self.assertTrue(data["read_only"])
        self.assertNotIn("top_pages", data)
        self.assertNotIn("devices", data)
        self.assertEqual(len(result["requests"]), 2)
        for request in result["requests"]:
            page_filter = request["dimensionFilter"]["filter"]
            self.assertEqual(page_filter["fieldName"], "pagePath")
            self.assertEqual(page_filter["stringFilter"], {
                "matchType": "EXACT",
                "value": "/events/brvtal-night",
                "caseSensitive": True,
            })
            self.assertNotIn("dimensions", request)
        self.assertEqual(len(result["cache_writes"]), 1)
        encoded = json.dumps(result).lower()
        self.assertNotIn("ephemeral-access-token", encoded)
        for forbidden in ("private_key", "client_email", "assertion", "client_id", "session_id", "ip_address"):
            self.assertNotIn(forbidden, encoded)

    def test_draft_private_unknown_or_inconsistent_event_fails_closed_without_pii_or_secret_material(self):
        for mode in ("draft", "private", "unknown", "mismatch"):
            with self.subTest(mode=mode):
                result = self.fixture(mode)
                data = result["data"]
                self.assertEqual((data["state"], data["status"]), ("UNAVAILABLE", "unavailable"))
                self.assertEqual(data["metrics"], {"users": None, "sessions": None, "views": None})
                self.assertIsNone(data["event_id"])
                self.assertIsNone(data["canonical_path"])
                self.assertEqual(result["request_count"], 0)
                self.assertEqual(result["requests"], [])
                self.assertEqual(result["cache_writes"], [])
                encoded = json.dumps(data).lower()
                for forbidden in ("private_key", "client_email", "access_token", "assertion", "session_id", "ip_address"):
                    self.assertNotIn(forbidden, encoded)

    def test_event_contract_preserves_existing_not_configured_unavailable_and_stale_semantics(self):
        not_configured = self.fixture("not_configured")["data"]
        self.assertEqual(
            (not_configured["state"], not_configured["status"], not_configured["freshness"]),
            ("NOT CONFIGURED", "not_configured", "not_configured"),
        )
        self.assertEqual(not_configured["event_id"], 42)
        self.assertEqual(not_configured["canonical_path"], "/events/brvtal-night")
        self.assertIsNone(not_configured["metrics"]["users"])

        unavailable = self.fixture("api_failure")["data"]
        self.assertEqual(
            (unavailable["state"], unavailable["status"], unavailable["freshness"]),
            ("UNAVAILABLE", "unavailable", "unavailable"),
        )
        self.assertIsNone(unavailable["metrics"]["views"])

        invalid = self.fixture("invalid_payload")["data"]
        self.assertEqual((invalid["state"], invalid["freshness"]), ("UNAVAILABLE", "unavailable"))
        self.assertIsNone(invalid["metrics"]["sessions"])

        stale = self.fixture("stale_cache")["data"]
        self.assertEqual((stale["state"], stale["freshness"], stale["cached"]), ("STALE", "stale", True))
        self.assertEqual(stale["metrics"], {"users": 8, "sessions": 9, "views": 10})
        self.assertEqual(stale["canonical_path"], "/events/brvtal-night")
        self.assertTrue(stale["read_only"])


if __name__ == "__main__":
    unittest.main()
