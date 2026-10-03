from __future__ import annotations

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
PACKET = ROOT / "docs" / "translation-provider-decision-v4.md"


class TranslationProviderDecisionTests(unittest.TestCase):
    def packet(self) -> str:
        return PACKET.read_text(encoding="utf-8")

    def candidate_section(self, text: str, name: str) -> str:
        pattern = rf"## Candidate: {re.escape(name)}\n(?P<body>.*?)(?=\n## Candidate:|\n## Cross-candidate)"
        match = re.search(pattern, text, flags=re.DOTALL)
        self.assertIsNotNone(match, f"missing candidate section: {name}")
        return match.group("body")

    def test_packet_exposes_neutral_options_and_dated_evidence_without_selecting_provider(self):
        text = self.packet()

        self.assertIn("Observed: `2026-10-03`", text)
        self.assertIn("Selected provider: **NONE**", text)
        self.assertIn("Current packet decision: **NONE**", text)
        self.assertIn("Option A — select a provider later", text)
        self.assertIn("Option B — continue provider-neutral fallback", text)
        self.assertIn("Option C — defer", text)

        candidates = {
            "Google Cloud Translation": (
                "https://cloud.google.com/products/translate/pricing",
                "https://docs.cloud.google.com/translate/data-usage",
                "https://docs.cloud.google.com/translate/quotas",
            ),
            "DeepL API": (
                "https://support.deepl.com/hc/en-us/articles/360021200939-DeepL-API-plans",
                "https://www.deepl.com/en/products/api",
            ),
            "Azure AI Translator": (
                "https://azure.microsoft.com/en-us/pricing/details/translator/",
                "https://learn.microsoft.com/en-us/azure/ai-foundry/responsible-ai/translator/data-privacy-security",
                "https://learn.microsoft.com/en-us/azure/ai-services/translator/service-limits",
            ),
        }

        for name, sources in candidates.items():
            with self.subTest(candidate=name):
                section = self.candidate_section(text, name)
                self.assertIn("Observed: `2026-10-03`", section)
                self.assertIn("BRVTAL benchmark quality: `UNKNOWN`", section)
                self.assertIn("BRVTAL benchmark latency: `UNKNOWN`", section)
                self.assertIn("Terms/contract status: `NOT_ACCEPTED`", section)
                for source in sources:
                    self.assertIn(source, section)

        lowered = text.lower()
        self.assertNotIn("selected provider: **google", lowered)
        self.assertNotIn("selected provider: **deepl", lowered)
        self.assertNotIn("selected provider: **azure", lowered)
        self.assertNotIn("current packet decision: **option", lowered)
        self.assertIn("evidence is intentionally insufficient to name a winner", lowered)
        self.assertIn("provider-specific\nquality/latency remains a missing prerequisite", lowered)

    def test_activation_requires_separate_explicit_owner_provider_and_spend_authority(self):
        text = self.packet()
        gate = text.split("## Mandatory activation gate", 1)[1].split(
            "## Safety / reversibility", 1
        )[0]

        self.assertIn("separate explicit owner decision", gate)
        self.assertIn("names the exact provider", gate)
        self.assertIn("explicitly authorizes any required provider spend", gate)
        self.assertIn("explicitly authorizes credential creation/provisioning", gate)
        self.assertIn("contractual/DPA terms", gate)
        self.assertIn("reversible rollout/rollback", gate)
        self.assertIn("A generic `sigue`", gate)
        self.assertIn("does\n**not** satisfy that activation gate", gate)

        safety = text.split("## Safety / reversibility", 1)[1]
        for statement in (
            "provider selected: **no**",
            "provider purchase/contract: **no**",
            "credentials/secrets created: **no**",
            "provider API/network calls: **no**",
            "production mutation: **no**",
            "private DISCADMIN data used: **no**",
            "spend authorized: **no**",
            "go-live authority added: **no**",
        ):
            self.assertIn(statement, safety)


if __name__ == "__main__":
    unittest.main()
