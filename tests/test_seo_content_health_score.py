"""#1068 S22-R043: executable PHP acceptance for synthetic editorial health."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_FILE = (ROOT / "config" / "seo_workspace.php").as_posix()


def item(**updates):
    value = {
        "kind": "entity",
        "mode": "AUTO",
        "public": True,
        "effective_title": "Sample Event — BRVTAL",
        "effective_description": "Synthetic event with valid metadata.",
        "effective_image": "https://example.test/assets/example.png",
        "editorial_source_complete": True,
        "content_image_required": True,
        "content_image_present": True,
    }
    value.update(updates)
    return value


def evaluate(rows, truncated=None):
    script = r"""
require $argv[1];
$items = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
$truncated = json_decode($argv[3], true, 32, JSON_THROW_ON_ERROR);
foreach ($items as &$item) {
    $health = brvtalSeoWorkspaceContentHealth($item);
    $item['health_state'] = $health['state'];
    $item['health_score'] = $health['score'];
    $item['health_max'] = $health['max'];
    $item['health_factors'] = $health['factors'];
    $item['warnings'] = brvtalSeoWorkspaceWarnings($item);
}
unset($item);
echo json_encode(['items'=>$items,
    'summary'=>brvtalSeoWorkspaceSummary($items, $truncated)],
    JSON_THROW_ON_ERROR);
"""
    run = subprocess.run(
        ["php", "-r", script, PHP_FILE, json.dumps(rows), json.dumps(truncated or [])],
        cwd=ROOT, capture_output=True, text=True, check=True, timeout=30,
    )
    return json.loads(run.stdout)


class ContentHealthScoreTests(unittest.TestCase):
    def test_score_determinista(self):
        healthy = item()
        result = evaluate([healthy])
        self.assertEqual(result, evaluate([copy.deepcopy(healthy)]))
        self.assertEqual(result["items"][0]["health_state"], "available")
        self.assertEqual(result["items"][0]["health_score"], 100)
        self.assertEqual(result["items"][0]["health_max"], 100)
        self.assertEqual(
            result["items"][0]["health_factors"],
            {"title": True, "description": True, "image": True, "content": True},
        )
        self.assertEqual(result["items"][0]["warnings"], [])
        self.assertEqual(result["summary"]["content_health_score"], 100)
        self.assertEqual(result["summary"]["content_health_scored"], 1)
        self.assertEqual(result["summary"]["content_health_state"], "available")

        degraded = item(effective_description="", editorial_source_complete=False)
        measured = evaluate([healthy, degraded])
        self.assertEqual(measured["items"][1]["health_score"], 50)
        self.assertEqual(measured["summary"]["content_health_score"], 75)
        self.assertEqual(measured["summary"]["healthy"], 1)
        self.assertEqual(measured["summary"]["issues"], 1)
        self.assertEqual(measured["summary"]["content_health_max"], 100)

        # Four fixed dimensions; an automatic route uses the canonical fallback,
        # not an invented "missing source image" warning.
        static = item(
            kind="static", mode="AUTO", content_image_required=False,
            content_image_present=None,
        )
        self.assertEqual(evaluate([static])["items"][0]["health_score"], 100)

    def test_alertas_y_fallbacks(self):
        missing_image = item(content_image_present=False)
        missing_text = item(editorial_source_complete=False)
        no_effective_image = item(
            kind="static", content_image_required=False,
            content_image_present=None, effective_image="",
        )
        malformed_image = item(effective_image="javascript:alert(1)")
        missing_meta = item(effective_title="", effective_description="")
        long_title = item(effective_title="A" * 71, effective_description="B" * 181)
        private = item(public=False, mode="MIXED")
        rows = evaluate([missing_image, missing_text, no_effective_image,
                         malformed_image, missing_meta, long_title, private])["items"]
        self.assertEqual(rows[0]["health_score"], 75)
        self.assertIn("MISSING_IMAGE", rows[0]["warnings"])
        self.assertNotIn("INCOMPLETE_EDITORIAL_INFORMATION", rows[0]["warnings"])
        self.assertEqual(rows[1]["health_score"], 75)
        self.assertIn("INCOMPLETE_EDITORIAL_INFORMATION", rows[1]["warnings"])
        self.assertEqual(rows[2]["health_score"], 75)
        self.assertIn("MISSING_IMAGE", rows[2]["warnings"])
        self.assertIn("MISSING_IMAGE", rows[3]["warnings"])
        self.assertEqual(rows[4]["health_score"], 50)
        self.assertIn("MISSING_TITLE", rows[4]["warnings"])
        self.assertIn("MISSING_DESCRIPTION", rows[4]["warnings"])
        self.assertEqual(rows[5]["health_score"], 50)
        self.assertIn("TITLE_LONG", rows[5]["warnings"])
        self.assertIn("DESCRIPTION_LONG", rows[5]["warnings"])
        self.assertIn("PRIVATE_WITH_OVERRIDE", rows[6]["warnings"])
        self.assertEqual(rows[6]["health_score"], 100)

        # Editorial source missing is independently signalled even if global
        # SEO description fallback kept the effective metadata nonempty.
        fallback = evaluate([item(
            editorial_source_complete=False,
            effective_description="Global fallback SEO description",
        )])["items"][0]
        self.assertIn("INCOMPLETE_EDITORIAL_INFORMATION", fallback["warnings"])
        self.assertNotIn("MISSING_DESCRIPTION", fallback["warnings"])

        api = (ROOT / "api" / "seo-workspace.php").read_text(encoding="utf-8")
        self.assertIn("brvtalSeoWorkspaceEntityIsPublic(", api)
        self.assertIn("brvtalSeoWorkspaceContentHealth($item)", api)
        self.assertIn("brvtalSeoWorkspaceWarnings($item)", api)
        self.assertIn("'editorial_source_complete'", api)
        self.assertIn("'content_image_required'", api)
        self.assertIn("'content_image_present'", api)
        self.assertIn("brvtal_admin_require();", api)
        self.assertIn("BRVTAL_SEO_WORKSPACE_RESOURCE_LIMIT = 100", api)
        self.assertIn("brvtalSeoWorkspaceSummary($items, $truncatedResources)", api)
        js = (ROOT / "discadmin" / "seo-workspace.js").read_text(encoding="utf-8")
        self.assertIn("EDITORIAL HEALTH", js)
        self.assertIn("esc(healthLabel(item)", js)
        self.assertIn("content_health_state", js)
        self.assertIn("state.items = [];", js)

    def test_truncamiento_falla_cerrado(self):
        healthy = item()
        partial = evaluate([healthy], ["events"])
        self.assertEqual(partial["items"][0]["health_score"], 100)
        self.assertEqual(partial["summary"]["status"], "partial")
        self.assertEqual(partial["summary"]["freshness"], "stale")
        self.assertEqual(partial["summary"]["content_health_state"], "partial")
        self.assertIsNone(partial["summary"]["content_health_score"])
        self.assertEqual(partial["summary"]["truncated_resources"], ["events"])

        # Unknown/incomplete/untrusted synthetic evidence never yields a score.
        for invalid in (
            item(editorial_source_complete=None),
            item(content_image_required="yes"),
            item(content_image_present=None),
            item(content_image_present="false"),
            item(effective_title=None),
            item(effective_image={"unexpected": "value"}),
            item(kind="unknown"),
        ):
            with self.subTest(invalid=invalid):
                result = evaluate([invalid])
                self.assertEqual(result["items"][0]["health_state"], "unavailable")
                self.assertIsNone(result["items"][0]["health_score"])
                self.assertEqual(result["summary"]["content_health_state"], "unavailable")
                self.assertIsNone(result["summary"]["content_health_score"])

        self.assertIsNone(evaluate([])["summary"]["content_health_score"])
        combined = evaluate([healthy, item(content_image_required="no")])
        self.assertIsNone(combined["summary"]["content_health_score"])
        self.assertEqual(combined["summary"]["content_health_scored"], 1)

        source = (ROOT / "config" / "seo_workspace.php").read_text(encoding="utf-8")
        for forbidden in ("curl_exec(", "file_get_contents('http", "INSERT INTO",
                          "UPDATE seo_", "CREATE TABLE", "ALTER TABLE"):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
