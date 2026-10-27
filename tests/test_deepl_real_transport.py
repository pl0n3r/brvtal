from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = ROOT / "tests" / "deepl_real_transport_scenarios.php"


def run_case(name: str) -> dict:
    run = subprocess.run(
        ["php", str(PHP), name], cwd=ROOT, capture_output=True,
        text=True, timeout=20, check=False,
    )
    if run.returncode:
        raise AssertionError(run.stdout + run.stderr)
    return json.loads(run.stdout)


class DeepLRealTransportTests(unittest.TestCase):
    def test_off_by_default_and_missing_key_fall_back_to_spanish(self):
        result = run_case("off")
        self.assertEqual(result["reasons"], ["provider_not_configured"] * 2)
        self.assertEqual(result["calls"], 0)
        self.assertEqual(result["fallback"], {
            "text": "Hola", "locale": "es", "source": "fallback", "translated": False
        })

    def test_request_contract_free_endpoint_header_and_limits(self):
        result = run_case("request")
        self.assertEqual(result["result"], "Hello")
        self.assertEqual(result["calls"], 1)
        self.assertEqual(result["reserved"], 1)
        self.assertEqual(result["invalid"], "invalid_public_text")
        self.assertEqual(result["version"], "deepl-free-es-en-us-v1")

    def test_errors_timeouts_and_budget_never_leak_secrets(self):
        result = run_case("errors")
        self.assertEqual(result["reasons"], {
            "timeout": "provider_unavailable", "http": "provider_unavailable",
            "invalid": "provider_invalid_response", "budget": "provider_unavailable",
        })
        self.assertEqual(result["calls"], 6)
        self.assertEqual(result["writes"], 0)
        for fallback in result["fallbacks"].values():
            self.assertEqual(fallback, {
                "text": "Hola", "locale": "es", "source": "fallback", "translated": False
            })
        self.assertNotIn("SYNTHETIC_ONLY_PRIVATE_FIXTURE", json.dumps(result))

    def test_validar_and_no_network_in_tests(self):
        result = run_case("contract")
        self.assertEqual(result["calls"], 0)
        self.assertFalse(result["network"])
        self.assertEqual(result["spanish"]["locale"], "es")


if __name__ == "__main__":
    unittest.main()
