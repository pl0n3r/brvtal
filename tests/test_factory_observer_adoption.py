#!/usr/bin/env python3
"""Contract tests for BRVTAL adoption of Factory v1 production observer."""

from __future__ import annotations

import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/production-deploy-observer.yml"


class FactoryObserverAdoptionTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.text = WORKFLOW.read_text(encoding="utf-8")

    def test_caller_uses_factory_v1_observer_with_brvtal_inputs(self) -> None:
        self.assertEqual(
            self.text.count(
                "uses: pl0n3r/factory/.github/workflows/observar.yml@v1"
            ),
            1,
        )
        for required in (
            "domain: https://www.brvtal.com.co",
            "health_path: /api/health.php",
            "paths: '[\"/\"]'",
            "version_source: config/version.php",
            "version_format: php-const",
            "version_key: BRVTAL_APP_VERSION",
            "require_schema: true",
            "label_language: en",
        ):
            self.assertIn(required, self.text)

    def test_hostinger_observer_has_bounded_convergence_window(self) -> None:
        self.assertEqual(self.text.count("convergence_attempts: 5"), 1)
        self.assertEqual(self.text.count("convergence_delay_seconds: 30"), 1)
        self.assertNotIn("convergence_attempts: 1", self.text)
        self.assertNotIn("convergence_delay_seconds: 0", self.text)

    def test_exact_sha_version_and_english_incident_contract(self) -> None:
        self.assertIn("expected_sha: ${{ github.sha }}", self.text)
        self.assertIn("version_key: BRVTAL_APP_VERSION", self.text)
        self.assertIn("health_path: /api/health.php", self.text)
        self.assertIn("require_schema: true", self.text)
        self.assertIn("label_language: en", self.text)
        self.assertNotIn("/api/deployment.php", self.text)

    def test_trigger_and_permissions_are_bounded(self) -> None:
        self.assertRegex(
            self.text,
            r"(?ms)^on:\s*\n\s+push:\s*\n\s+branches:\s*\[main\]\s*$",
        )
        self.assertNotIn("pull_request:", self.text)
        self.assertNotIn("pull_request_target:", self.text)
        self.assertNotIn("issue_comment:", self.text)
        self.assertIn("contents: read", self.text)
        self.assertIn("issues: write", self.text)
        for forbidden in (
            "contents: write",
            "actions: write",
            "checks: write",
            "packages: write",
            "secrets: inherit",
        ):
            self.assertNotIn(forbidden, self.text)

    def test_no_local_duplicate_observer_logic_remains(self) -> None:
        for forbidden in (
            "runs-on:",
            "steps:",
            "actions/checkout",
            "curl ",
            "jq ",
            "gh issue",
            "python3 ",
            "max_attempts=",
            "sleep_seconds=",
        ):
            self.assertNotIn(forbidden, self.text)

        self.assertNotRegex(self.text, re.compile(r"\brun:\s*[|>]"))


if __name__ == "__main__":
    unittest.main()
