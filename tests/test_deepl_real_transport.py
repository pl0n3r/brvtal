from __future__ import annotations

import json
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = ROOT / "tests" / "deepl_real_transport_scenarios.php"
TRANSPORT = ROOT / "config" / "public_translation_deepl_transport.php"
KEY = "fixture-key-must-never-be-exposed"
SPANISH = {"text": "Hola", "locale": "es", "source": "fallback", "translated": False}


def run_scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(SCENARIOS), name], cwd=ROOT, text=True,
        capture_output=True, timeout=20, check=False,
    )
    if result.returncode:
        raise AssertionError(result.stdout + result.stderr)
    return json.loads(result.stdout)


class DeepLRealTransportTests(unittest.TestCase):
    def test_off_by_default_and_missing_key_fall_back_to_spanish(self) -> None:
        data = run_scenario("off")
        self.assertTrue(all(data["off"].values()), data["off"])
        self.assertEqual(data["direct_reason"], "deepl_disabled")
        self.assertEqual(data["http_calls"], 0)

    def test_request_contract_free_endpoint_header_and_limits(self) -> None:
        data = run_scenario("contract")
        request = data["request"]
        self.assertEqual(request["url"], "https://api-free.deepl.com/v2/translate")
        self.assertEqual(request["timeout"], 3)
        self.assertEqual(request["body"], {"text": ["Hola mundo"], "source_lang": "ES", "target_lang": "EN-US"})
        self.assertTrue(data["key_in_headers_only"])
        self.assertFalse(data["key_in_body"])
        self.assertEqual(data["translated"], "Hello world")
        self.assertEqual(data["version"], "deepl-api-free-es-en-v1")
        self.assertEqual(data["calls"], 1)
        self.assertEqual(data["rejects"], ["unsupported_locale", "invalid_public_text"])
        self.assertEqual(data["direct_rejects"], ["deepl_bad_request"] * 3)
        self.assertEqual(data["direct_http_calls"], 0)

    def test_errors_timeouts_and_budget_never_leak_secrets(self) -> None:
        errors = run_scenario("errors")
        self.assertEqual(errors["reasons"], {
            "transport": "provider_unavailable", "http_456": "provider_unavailable",
            "http_403": "provider_unavailable", "malformed": "provider_unavailable",
            "shape": "provider_invalid_response",
        })
        for fallback in errors["fallbacks"].values():
            self.assertEqual(fallback, SPANISH)
        budget = run_scenario("budget")
        self.assertEqual(budget["exhausted"], "deepl_budget_exhausted")
        self.assertEqual(budget["malformed_record"], "deepl_budget_unavailable")
        self.assertEqual(budget["empty_record"], "deepl_budget_unavailable")
        self.assertEqual(budget["malformed_http_calls"], 0)
        self.assertEqual(budget["calls_at_limit"], 2)
        self.assertGreater(budget["calls_after_new_month"], budget["calls_at_limit"])
        for payload in (errors, budget):
            self.assertNotIn(KEY, json.dumps(payload))

    def test_validar_and_no_network_in_tests(self) -> None:
        source = TRANSPORT.read_text(encoding="utf-8")
        scenarios = SCENARIOS.read_text(encoding="utf-8")
        self.assertIn("BRVTAL_DEEPL_ENABLED", source)
        self.assertIn("api-free.deepl.com", source)
        self.assertIsNone(re.search(r"DeepL-Auth-Key [A-Za-z0-9-]{20,}:fx", source + scenarios))
        self.assertNotIn("curl_init", scenarios)
        self.assertNotIn("curlRequest", scenarios)
        self.assertIsNone(re.search(r"\bgetenv\(", scenarios))


if __name__ == "__main__":
    unittest.main()
