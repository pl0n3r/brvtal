#!/usr/bin/env python3
"""Contract for exact-main Concept 05 production visual evidence."""

from __future__ import annotations

import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
CAPTURE = ROOT / "tests/e2e/production-concept05-visual-evidence.mjs"
PERFORMANCE = ROOT / ".github/workflows/production-performance.yml"
CI = ROOT / ".github/workflows/update-release-metadata.yml"
PIN = "043fb46d1a93c77aae656e7c1c64a875d1fc6a0a"


class Concept05ProductionVisualEvidenceTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.capture = CAPTURE.read_text(encoding="utf-8")
        cls.performance = PERFORMANCE.read_text(encoding="utf-8")
        cls.ci = CI.read_text(encoding="utf-8")

    def test_live_capture_pins_canonical_viewports_and_full_page_screenshots(self) -> None:
        for marker in (
            "width: 390, height: 844",
            "width: 1440, height: 900",
            "fullPage: true",
            "animations: 'disabled'",
            "caret: 'hide'",
            "scrollIntoViewIfNeeded()",
            "window.scrollTo(0, 0)",
            "production-concept05-mobile-390.png",
            "production-concept05-desktop-1440.png",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.capture)

    def test_live_capture_is_public_read_only_deterministic_and_sha_bound(self) -> None:
        for marker in (
            "https://www.brvtal.com.co/",
            "BRVTAL_VISUAL_SOURCE_SHA",
            "/^[0-9a-f]{40}$/",
            "document.documentElement.classList.add('c5-visual-test')",
            "createHash('sha256')",
            "schemaVersion: 1",
            "sourceSha",
            "finalUrl",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.capture)
        for forbidden in ("storageState", "extraHTTPHeaders", "Authorization", "page.request.post", "page.request.put", "page.request.delete"):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, self.capture)

    def test_production_performance_artifact_preserves_v7_retention_and_adds_visual_evidence(self) -> None:
        self.assertEqual(1, self.performance.count("uses: actions/upload-artifact@"))
        self.assertIn(f"actions/upload-artifact@{PIN} # v7.0.1", self.performance)
        self.assertIn("Capture Concept 05 production visual evidence", self.performance)
        self.assertIn("BRVTAL_VISUAL_SOURCE_SHA: ${{ github.event.workflow_run.head_sha || github.sha }}", self.performance)
        for marker in (
            "artifacts/production-performance-*.json",
            "artifacts/production-concept05-*.png",
            "artifacts/production-concept05-visual.json",
            "if-no-files-found: error",
            "retention-days: 14",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.performance)

    def test_fast_gate_executes_visual_evidence_regression(self) -> None:
        self.assertIn("Test CI resilience tooling", self.ci)
        self.assertIn("python3 -m unittest", self.ci)
        self.assertIn("tests/test_public_concept05_production_visual_evidence.py", self.ci)

    def test_post_merge_completion_contract_requires_two_pngs_and_manifest(self) -> None:
        for marker in (
            "production-concept05-mobile-390.png",
            "production-concept05-desktop-1440.png",
            "production-concept05-visual.json",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.capture)
        self.assertIn("artifacts/production-concept05-*.png", self.performance)
        self.assertIn("artifacts/production-concept05-visual.json", self.performance)


if __name__ == "__main__":
    unittest.main()
