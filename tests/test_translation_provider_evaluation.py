from __future__ import annotations

from datetime import datetime
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
CONTRACT = ROOT / "docs" / "translation-provider-evaluation-v4.json"
BENCHMARK = ROOT / "tests" / "fixtures" / "translation-provider-benchmark-es.json"


def load_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def assert_iso_date(testcase: unittest.TestCase, value: str) -> None:
    testcase.assertIsInstance(value, str)
    parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    testcase.assertIsNotNone(parsed.tzinfo)


class TranslationProviderEvaluationTests(unittest.TestCase):
    def test_evaluation_contract_requires_dated_provenance_cost_and_privacy_without_secrets(self):
        contract = load_json(CONTRACT)
        schema = contract["candidate_schema"]
        required = set(schema["required"])

        self.assertTrue({
            "candidate_ref",
            "observed_at",
            "source_url",
            "pricing_observed_at",
            "cost_envelope",
            "privacy",
            "quality",
            "latency",
            "rate_limits",
        }.issubset(required))
        self.assertFalse(schema["additional_properties"])

        properties = schema["properties"]
        self.assertIn("currency", properties["cost_envelope"]["required"])
        self.assertIn("policy_url", properties["privacy"]["required"])
        self.assertIn("observed_at", properties["privacy"]["required"])
        self.assertIn("observed_at", properties["rate_limits"]["required"])

        complete = {
            "candidate_ref": "candidate-a",
            "observed_at": "2026-10-03T00:00:00Z",
            "source_url": "https://example.invalid/provider",
            "pricing_observed_at": "2026-10-03T00:00:00Z",
            "cost_envelope": {
                "currency": "USD",
                "input_unit": "million_characters",
                "output_unit": "million_characters",
                "input_unit_cost": 1.0,
                "output_unit_cost": 1.0,
            },
            "privacy": {
                "policy_url": "https://example.invalid/privacy",
                "observed_at": "2026-10-03T00:00:00Z",
                "training_use": "unknown",
                "retention": "unknown",
                "region_notes": "unknown",
            },
            "quality": {
                "benchmark_ref": "brvtal-public-editorial-es-v1",
                "protected_terms_preserved": True,
                "score": 90,
            },
            "latency": {"sample_size": 5, "p50_ms": 100, "p95_ms": 250},
            "rate_limits": {
                "source_url": "https://example.invalid/limits",
                "observed_at": "2026-10-03T00:00:00Z",
                "notes": "public documentation",
            },
        }

        self.assertEqual(set(complete), required)
        assert_iso_date(self, complete["observed_at"])
        assert_iso_date(self, complete["pricing_observed_at"])
        assert_iso_date(self, complete["privacy"]["observed_at"])
        assert_iso_date(self, complete["rate_limits"]["observed_at"])

        for missing in ("source_url", "pricing_observed_at", "cost_envelope", "privacy"):
            with self.subTest(missing=missing):
                incomplete = dict(complete)
                incomplete.pop(missing)
                self.assertNotEqual(set(incomplete), required)

        serialized = json.dumps(contract, ensure_ascii=False).lower()
        for forbidden in ("api_key", "access_token", "client_secret", "private_key", "password"):
            self.assertNotIn(forbidden, serialized)

        safety = contract["safety"]
        self.assertFalse(safety["network_calls"])
        self.assertFalse(safety["production_calls"])
        self.assertFalse(safety["runtime_authority"])
        self.assertFalse(safety["provider_activation"])
        self.assertFalse(safety["spend_authorized"])

    def test_public_benchmark_corpus_preserves_protected_terms_and_excludes_private_content(self):
        corpus = load_json(BENCHMARK)

        self.assertEqual(corpus["source"], "synthetic-public-style")
        self.assertFalse(corpus["contains_private_content"])
        self.assertGreaterEqual(len(corpus["samples"]), 5)

        protected = set(corpus["protected_terms"])
        self.assertTrue({"BRVTAL", "DISCADMIN", "hard techno", "Frenchcore", "Uptempo"}.issubset(protected))

        joined = "\n".join(sample["text"] for sample in corpus["samples"])
        for term in protected:
            self.assertIn(term, joined)

        lowered = joined.lower()
        for private_marker in (
            "@",
            "customer_id",
            "tenant_id",
            "password",
            "token=",
            "api_key",
            "/discadmin/users",
        ):
            self.assertNotIn(private_marker, lowered)

        ids = [sample["id"] for sample in corpus["samples"]]
        self.assertEqual(len(ids), len(set(ids)))


if __name__ == "__main__":
    unittest.main()
