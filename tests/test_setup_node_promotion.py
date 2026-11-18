"""Regresiones de la promoción canónica de Dependabot #742."""
from __future__ import annotations

import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SETUP_NODE_V7_SHA = "820762786026740c76f36085b0efc47a31fe5020"
SOURCE_WORKFLOWS = (
    ".github/workflows/production-authenticated-smoke.yml",
    ".github/workflows/production-page-write-smoke.yml",
    ".github/workflows/production-performance.yml",
)


class SetupNodePromotionTests(unittest.TestCase):
    def test_three_workflows_pin_setup_node_v7_sha(self):
        expected = f"uses: actions/setup-node@{SETUP_NODE_V7_SHA} # v7.0.0"
        pattern = re.compile(r"(?m)^\s*uses:\s*actions/setup-node@([^\s#]+)")
        for path in SOURCE_WORKFLOWS:
            with self.subTest(path=path):
                content = (ROOT / path).read_text(encoding="utf-8")
                self.assertEqual(content.count(expected), 1)
                self.assertEqual(pattern.findall(content), [SETUP_NODE_V7_SHA])

    def test_scope_matches_dependabot_source_paths(self):
        self.assertEqual(
            set(SOURCE_WORKFLOWS),
            {
                ".github/workflows/production-authenticated-smoke.yml",
                ".github/workflows/production-page-write-smoke.yml",
                ".github/workflows/production-performance.yml",
            },
        )
        for path in SOURCE_WORKFLOWS:
            self.assertTrue((ROOT / path).is_file(), path)

    def test_release_version_matches_package_metadata(self):
        version_php = (ROOT / "config/version.php").read_text(encoding="utf-8")
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        match = re.search(r"BRVTAL_APP_VERSION\s*=\s*'([^']+)'", version_php)
        self.assertIsNotNone(match)
        self.assertEqual(package["version"], match.group(1))
    def test_readme_dashboard_matches_exact_delta(self):
        readme = (ROOT / "README.md").read_text(encoding="utf-8")
        self.assertIn("<!-- brvtal:git-delta -->", readme)
        self.assertIn("<!-- brvtal:gate-plan -->", readme)
        for path in (
            *SOURCE_WORKFLOWS,
            "config/version.php",
            "package.json",
            "README.md",
            "tests/test_setup_node_promotion.py",
        ):
            self.assertIn("`" + path + "`", readme)

    def test_source_pr_is_preserved_as_external_evidence(self):
        readme = (ROOT / "README.md").read_text(encoding="utf-8")
        self.assertIn("https://github.com/pl0n3r/brvtal/pull/742", readme)
        self.assertIn("Dependabot #742", readme)
        self.assertIn(SETUP_NODE_V7_SHA, readme)


if __name__ == "__main__":
    unittest.main()
