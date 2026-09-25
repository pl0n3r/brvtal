import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/factory-labels.yml"


class FactoryLabelsAdoptionTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.text = WORKFLOW.read_text(encoding="utf-8")

    def test_reusable_callers_cover_validate_sync_and_sweep_in_english(self):
        self.assertEqual(
            self.text.count("uses: pl0n3r/factory/.github/workflows/etiquetas.yml@v1"),
            4,
        )
        self.assertEqual(self.text.count("language: en"), 4)
        self.assertIn("mode: sync", self.text)
        self.assertEqual(self.text.count("mode: validate"), 2)
        self.assertIn("mode: sweep", self.text)

    def test_validation_is_metadata_only_with_minimal_permissions(self):
        self.assertIn("pull_request:", self.text)
        self.assertNotIn("pull_request_target:", self.text)
        self.assertNotIn("actions/checkout", self.text)
        self.assertNotIn("run:", self.text)
        self.assertGreaterEqual(self.text.count("contents: read"), 5)
        self.assertGreaterEqual(self.text.count("issues: write"), 4)
        self.assertGreaterEqual(self.text.count("pull-requests: read"), 4)
        for forbidden in ("contents: write", "actions: write", "checks: write", "secrets: inherit"):
            self.assertNotIn(forbidden, self.text)

    def test_sync_and_sweep_are_bounded_and_preserve_coordinator_status_authority(self):
        self.assertIn("concurrency:", self.text)
        self.assertIn("cancel-in-progress: false", self.text)
        self.assertIn("github.event_name == 'schedule'", self.text)
        self.assertIn("github.event_name == 'push'", self.text)
        self.assertNotIn("status:", self.text)
        self.assertNotIn("gh api", self.text)

    def test_consumer_contract_requires_factory_v1_labels_lifecycle(self):
        self.assertIn("name: Factory Labels", self.text)
        self.assertEqual(self.text.count("etiquetas.yml@v1"), 4)
        local_label_implementations = [
            p for p in (ROOT / "scripts").glob("*label*")
            if p.is_file()
        ]
        self.assertEqual(local_label_implementations, [])

    def test_open_metadata_fixture_has_exactly_one_canonical_label_per_dimension(self):
        fixtures = [
            {"type: infrastructure", "priority: high", "status: available", "role: qa"},
            {"type: product", "priority: medium", "status: in review", "role: security"},
            {"type: incident", "priority: critical", "status: blocked", "role: sre"},
        ]
        dimensions = {
            "type": re.compile(r"^type: "),
            "priority": re.compile(r"^priority: "),
            "status": re.compile(r"^status: "),
        }
        for labels in fixtures:
            for name, pattern in dimensions.items():
                matches = [label for label in labels if pattern.match(label)]
                self.assertEqual(len(matches), 1, (name, labels))


if __name__ == "__main__":
    unittest.main()
