from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = ROOT / "tests" / "deepl_translation_adapter_scenarios.php"


def run_scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(SCENARIOS), name],
        cwd=ROOT,
        text=True,
        capture_output=True,
        timeout=20,
        check=False,
    )
    if result.returncode:
        raise AssertionError(result.stdout + result.stderr)
    return json.loads(result.stdout)


class DeepLAdapterTests(unittest.TestCase):
    def test_missing_configuration_fails_closed_without_network(self) -> None:
        data = run_scenario("missing")
        self.assertEqual(data["reason"], "provider_not_configured")
        self.assertEqual(data["transport_calls"], 0)
        self.assertEqual(data["result"], {
            "text": "Hola", "locale": "es", "source": "fallback", "translated": False
        })

    def test_fake_transport_validates_es_en_and_response_limits(self) -> None:
        data = run_scenario("valid")
        self.assertEqual(data["translated"], "EN Hola mundo")
        self.assertEqual(data["version"], "deepl-fake-es-en-v1")
        self.assertEqual(data["transport_calls"], 1)
        self.assertEqual(data["rejects"], [
            "invalid_public_text", "unsupported_locale",
            "invalid_public_text", "invalid_public_text",
            "invalid_public_text", "provider_invalid_response",
            "provider_invalid_response",
        ])
        self.assertNotIn("fixture-key-must-never-be-exposed", json.dumps(data))

    def test_provider_errors_leave_canonical_spanish_and_safe_evidence(self) -> None:
        data = run_scenario("errors")
        self.assertEqual(data["reasons"], {
            "timeout": "provider_unavailable",
            "http": "provider_unavailable",
            "malformed": "provider_invalid_response",
        })
        for fallback in data["fallbacks"].values():
            self.assertEqual(fallback, {
                "text": "Hola", "locale": "es",
                "source": "fallback", "translated": False,
            })
        self.assertNotIn("fixture-key-must-never-be-exposed", json.dumps(data))
        self.assertNotIn("script", json.dumps(data))

    def test_adapter_works_with_existing_cache_contract(self) -> None:
        data = run_scenario("cache")
        self.assertEqual(data["first"]["source"], "provider")
        self.assertEqual(data["second"]["source"], "cache")
        self.assertEqual(data["first"]["locale"], "en")
        self.assertEqual(data["second"]["text"], data["first"]["text"])
        self.assertEqual(data["spanish"]["source"], "canonical")
        self.assertEqual(data["changed"]["source"], "provider")
        self.assertIsNone(data["private"])
        self.assertTrue(data["trusted"])
        self.assertEqual(data["calls_before_visibility"], 2)
        self.assertGreater(data["transport_calls"], 2)
        self.assertGreaterEqual(data["cache_keys"], 4)


if __name__ == "__main__":
    unittest.main()
