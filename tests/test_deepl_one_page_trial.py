#!/usr/bin/env python3
"""DeepL one-page build-ahead: execute real PHP against a fake transport only."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = ROOT / "tests" / "deepl_one_page_trial_scenarios.php"


def scenario(name: str) -> dict:
    done = subprocess.run(
        ["php", str(SCENARIOS), name], cwd=ROOT, text=True,
        capture_output=True, timeout=20, check=False,
    )
    if done.returncode:
        raise AssertionError(done.stdout + done.stderr)
    return json.loads(done.stdout)


class DeepLOnePageTrialTests(unittest.TestCase):
    def test_disabled_never_calls_provider(self) -> None:
        data = scenario("disabled")
        self.assertTrue(all(data["ok"].values()), data)
        self.assertEqual(data["calls"], 0)
        self.assertEqual(data["reads"], 0)
        self.assertEqual(data["writes"], 0)

    def test_exactly_one_public_page(self) -> None:
        data = scenario("scope")
        result = data["results"]
        self.assertTrue(result["correct"]["translated"], result)
        self.assertTrue(all(row["body_unchanged"] for row in result.values()))
        for key, row in result.items():
            if key != "correct":
                self.assertFalse(row["translated"], key)

    def test_private_untrusted_and_budget_fail_closed(self) -> None:
        data = scenario("failclosed")
        self.assertTrue(all(data["rejects"].values()), data)
        self.assertEqual(data["before_transport_failure"], 0)
        self.assertTrue(data["failure_fallback"])
        source = (ROOT / "config/public_translation_deepl_page_trial.php").read_text()
        self.assertIn("brvtalPublicEditorialOverlayEligible", source)
        self.assertIn("BrvtalDeepLHttpTransport::adapterFromEnvironment", source)
        self.assertNotIn("curl_init(", source)
        self.assertNotIn("curl_init(", SCENARIOS.read_text())

    def test_real_production_receipt_required(self) -> None:
        data = scenario("receipt")
        self.assertTrue(data["claimed_receipt_not_authority"])
        self.assertEqual(data["calls"], 0)
        self.assertEqual(data["cache_writes"], 0)
        doc = (ROOT / "docs/deepl-one-page-trial.md").read_text(encoding="utf-8")
        self.assertIn("VALIDATED_IN_PRODUCTION", doc)
        self.assertIn("D-043", doc)
        self.assertIn("sin permiso de operación", doc)


if __name__ == "__main__":
    unittest.main()
