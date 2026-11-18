import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class AdminCodeRabbitSignalsTests(unittest.TestCase):
    def fixture(self, mode="passed"):
        config = (ROOT / "config" / "admin_coderabbit_signals.php").as_posix()
        script = f"""
require {json.dumps(config)};
$mode = {json.dumps(mode)};
$request = function(string $url) use ($mode): array {{
    if ($mode === 'unavailable') throw new RuntimeException('offline');
    if (str_contains($url, '/search/issues?')) {{
        return ['status'=>200,'body'=>json_encode([
            'incomplete_results'=>false,
            'items'=>[[
                'number'=>775,
                'html_url'=>'https://github.com/pl0n3r/brvtal/pull/775',
            ]],
        ])];
    }}
    if (str_ends_with($url, '/pulls/775')) {{
        return ['status'=>200,'body'=>json_encode([
            'head'=>['sha'=>str_repeat('a', 40)],
        ])];
    }}
    if (str_contains($url, '/pulls/775/reviews?')) {{
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $page = (int)($query['page'] ?? 1);
        if ($mode === 'paginated' && $page === 1) {{
            $items = [];
            for ($index = 0; $index < 100; $index++) {{
                $items[] = [
                    'user'=>['login'=>'other-bot[bot]','type'=>'Bot'],
                    'body'=>'noise',
                    'commit_id'=>str_repeat('a', 40),
                    'submitted_at'=>gmdate('c', time() - 100 - $index),
                    'html_url'=>'https://github.com/pl0n3r/brvtal/pull/775#pullrequestreview-noise-' . $index,
                ];
            }}
            return ['status'=>200,'body'=>json_encode($items)];
        }}
        $body = match ($mode) {{
            'findings' => '**Actionable comments posted: 2**',
            'passed', 'stale_commit', 'spoofed', 'paginated' => '**Actionable comments posted: 0**',
            default => '',
        }};
        $login = $mode === 'spoofed' ? 'coderabbitai-x' : 'coderabbitai[bot]';
        $type = $mode === 'spoofed' ? 'User' : 'Bot';
        $commit = $mode === 'stale_commit' ? str_repeat('b', 40) : str_repeat('a', 40);
        $items = $body === '' ? [] : [[
            'user'=>['login'=>$login,'type'=>$type],
            'body'=>$body,
            'commit_id'=>$commit,
            'submitted_at'=>gmdate('c'),
            'html_url'=>'https://github.com/pl0n3r/brvtal/pull/775#pullrequestreview-1',
        ]];
        return ['status'=>200,'body'=>json_encode($items)];
    }}
    if (str_contains($url, '/issues/775/comments?')) {{
        $body = match ($mode) {{
            'rate_limited' => 'Review rate limited.',
            'pending' => 'Currently processing new changes in this PR.',
            default => '',
        }};
        $items = $body === '' ? [] : [[
            'user'=>['login'=>'coderabbitai[bot]','type'=>'Bot'],
            'body'=>$body,
            'updated_at'=>gmdate('c'),
            'html_url'=>'https://github.com/pl0n3r/brvtal/pull/775#issuecomment-1',
        ]];
        return ['status'=>200,'body'=>json_encode($items)];
    }}
    throw new RuntimeException('unexpected_url:' . $url);
}};
echo json_encode(brvtalAdminCodeRabbitSignals($request), JSON_THROW_ON_ERROR);
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

    def test_normalizes_terminal_coderabbit_review_states(self):
        self.assertEqual(self.fixture("passed")["review_state"], "passed")
        self.assertEqual(self.fixture("findings")["review_state"], "findings")
        self.assertEqual(self.fixture("rate_limited")["review_state"], "rate_limited")

    def test_missing_terminal_evidence_is_pending_not_passed(self):
        data = self.fixture("pending")
        self.assertEqual(data["status"], "available")
        self.assertEqual(data["review_state"], "pending")
        self.assertNotEqual(data["review_state"], "passed")

    def test_coderabbit_failure_is_isolated_from_other_development_sources(self):
        data = self.fixture("unavailable")
        self.assertEqual(data["status"], "unavailable")
        self.assertEqual(data["freshness"], "unavailable")
        self.assertEqual(data["review_state"], "unavailable")
        js = (ROOT / "discadmin" / "dashboard-v2.js").read_text(encoding="utf-8")
        self.assertIn("hydrateDevelopmentSource(results,serial,6,ENDPOINTS.development)", js)
        self.assertIn("hydrateDevelopmentSource(results,serial,7,ENDPOINTS.sonar)", js)
        self.assertIn("hydrateDevelopmentSource(results,serial,8,ENDPOINTS.coderabbit)", js)

    def test_spoofed_coderabbit_login_is_ignored(self):
        data = self.fixture("spoofed")
        self.assertEqual(data["review_state"], "pending")
        self.assertNotEqual(data["review_state"], "passed")

    def test_terminal_review_for_stale_head_is_pending(self):
        data = self.fixture("stale_commit")
        self.assertEqual(data["review_state"], "pending")

    def test_paginates_to_newest_coderabbit_evidence(self):
        data = self.fixture("paginated")
        self.assertEqual(data["review_state"], "passed")

    def test_no_coderabbit_secret_or_mutation_surface(self):
        config = (ROOT / "config" / "admin_coderabbit_signals.php").read_text(encoding="utf-8").lower()
        api = (ROOT / "api" / "admin-coderabbit-signals.php").read_text(encoding="utf-8").lower()
        js = (ROOT / "discadmin" / "dashboard-v2.js").read_text(encoding="utf-8").lower()
        self.assertNotIn("coderabbit_token", config + api + js)
        self.assertNotIn("coderabbit_api_key", config + api + js)
        self.assertNotIn("@coderabbitai review", config + api + js)
        for verb in ("post", "patch", "delete"):
            self.assertNotIn(f"method:'{verb}'", api)
            self.assertNotIn(f'method:"{verb}"', js)

    def test_dashboard_renders_safe_coderabbit_state_and_link(self):
        js = (ROOT / "discadmin" / "dashboard-v2.js").read_text(encoding="utf-8")
        self.assertIn("coderabbit:'/api/admin-coderabbit-signals.php'", js)
        self.assertIn("CODERABBIT", js)
        self.assertIn("review_state", js)
        self.assertIn('rel="noopener noreferrer"', js)
        self.assertIn("https:\\/\\/github\\.com\\/pl0n3r\\/brvtal", js)

    def test_review_state_fixtures_are_deterministic(self):
        expected = {
            "passed": "passed",
            "findings": "findings",
            "rate_limited": "rate_limited",
            "pending": "pending",
            "unavailable": "unavailable",
        }
        for mode, state in expected.items():
            self.assertEqual(self.fixture(mode)["review_state"], state)

    def test_deploy_bound_version_is_0_1_92(self):
        version = (ROOT / "config" / "version.php").read_text(encoding="utf-8")
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        self.assertIn("BRVTAL_APP_VERSION = '0.1.92'", version)
        self.assertEqual(package["version"], "0.1.92")


if __name__ == "__main__":
    unittest.main()
