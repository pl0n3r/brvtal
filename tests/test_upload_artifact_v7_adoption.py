#!/usr/bin/env python3
"""Regression contract for immutable upload-artifact v7 adoption."""

from __future__ import annotations

import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
PIN = "043fb46d1a93c77aae656e7c1c64a875d1fc6a0a"
LEGACY_PIN = "ea165f8d65b6e75b540449e92b4886f43607fa02"
CI = ROOT / ".github/workflows/update-release-metadata.yml"
RECOVERY_CONTRACT = ROOT / "tests/backup-recovery-rehearsal-contract.php"
OPERATIONS_CONTRACT = ROOT / "tests/project-operations-contract.php"
PRODUCTION_SMOKE_CONTRACT = ROOT / "tests/production-smoke-contract.php"

TARGETS = {
    ROOT / ".github/workflows/production-authenticated-smoke.yml": {
        "condition": "if: always()",
        "name": "name: production-authenticated-smoke-${{ github.run_id }}",
        "paths": ("path: artifacts/production-authenticated-smoke.json",),
        "missing": "if-no-files-found: warn",
    },
    ROOT / ".github/workflows/production-page-write-smoke.yml": {
        "condition": "if: always()",
        "name": "name: production-page-write-smoke-${{ github.run_id }}",
        "paths": ("path: artifacts/production-page-write-smoke.json",),
        "missing": "if-no-files-found: warn",
    },
    ROOT / ".github/workflows/production-performance.yml": {
        "condition": "if: success() && steps.connectivity.outputs.reachable == 'true'",
        "name": "name: production-performance-${{ github.run_id }}",
        "paths": (
            "artifacts/production-performance-*.json",
            "artifacts/production-concept05-*.png",
            "artifacts/production-concept05-visual.json",
        ),
        "missing": "if-no-files-found: error",
    },
    ROOT / ".github/workflows/update-release-metadata.yml": {
        "condition": "if: always()",
        "name": "name: backup-recovery-rehearsal-${{ github.run_id }}",
        "paths": ("path: artifacts/backup-recovery-rehearsal.json",),
        "missing": "if-no-files-found: warn",
    },
}


def upload_block(text: str, *, target_name: str | None = None) -> str:
    lines = text.splitlines()
    indices = [index for index, line in enumerate(lines) if "uses: actions/upload-artifact@" in line]
    blocks = ["\n".join(lines[max(0, index - 2): index + 12]) for index in indices]
    if target_name is not None:
        blocks = [block for block in blocks if target_name in block]
    if len(blocks) != 1:
        raise AssertionError(f"expected one matching upload-artifact block, found {len(blocks)}")
    return blocks[0]


def validate_upload_block(block: str) -> None:
    expected = f"uses: actions/upload-artifact@{PIN} # v7.0.1"
    if expected not in block:
        raise AssertionError("upload-artifact must use the immutable v7.0.1 SHA")
    if "retention-days: 14" not in block:
        raise AssertionError("upload-artifact must preserve fourteen-day retention")
    if "archive: false" in block:
        raise AssertionError("direct uploads are outside this maintenance scope")


class UploadArtifactV7AdoptionTests(unittest.TestCase):
    def test_target_workflows_pin_upload_artifact_v7_0_1(self) -> None:
        for path, expected in TARGETS.items():
            with self.subTest(path=path.name):
                text = path.read_text(encoding="utf-8")
                block = upload_block(text, target_name=expected["name"])
                validate_upload_block(block)
                self.assertNotIn(f"actions/upload-artifact@{LEGACY_PIN}", text)
                self.assertNotIn("actions/upload-artifact@v4", text)
                self.assertNotIn("actions/upload-artifact@v7", text)

    def test_upload_contract_preserves_paths_conditions_and_fourteen_day_retention(self) -> None:
        for path, expected in TARGETS.items():
            with self.subTest(path=path.name):
                text = path.read_text(encoding="utf-8")
                block = upload_block(text, target_name=expected["name"])
                validate_upload_block(block)
                self.assertIn(expected["condition"], block)
                self.assertIn(expected["name"], block)
                for expected_path in expected["paths"]:
                    self.assertIn(expected_path, block)
                self.assertIn(expected["missing"], block)
                self.assertEqual(block.count("retention-days: 14"), 1)

    def test_recovery_and_operations_contracts_track_current_pin_and_retention_semantics(self) -> None:
        recovery = RECOVERY_CONTRACT.read_text(encoding="utf-8")
        operations = OPERATIONS_CONTRACT.read_text(encoding="utf-8")
        smoke = PRODUCTION_SMOKE_CONTRACT.read_text(encoding="utf-8")
        for text in (recovery, operations, smoke):
            self.assertIn(PIN, text)
            self.assertIn("retention-days: 14", text)
            self.assertNotIn(LEGACY_PIN, text)
            self.assertNotIn("actions/upload-artifact@v4", text)
        self.assertIn("backup-recovery-rehearsal-${{ github.run_id }}", recovery)
        self.assertIn("production-performance-${{ github.run_id }}", operations)
        self.assertIn("production-authenticated-smoke", smoke)
        self.assertIn("production-page-write-smoke", smoke)

    def test_target_workflows_reject_legacy_mutable_or_unretained_uploads(self) -> None:
        valid = "\n".join([
            "if: always()",
            f"uses: actions/upload-artifact@{PIN} # v7.0.1",
            "with:",
            "  name: evidence-${{ github.run_id }}",
            "  path: artifacts/evidence.json",
            "  if-no-files-found: warn",
            "  retention-days: 14",
        ])
        validate_upload_block(valid)
        invalid = (
            valid.replace(PIN, LEGACY_PIN),
            valid.replace(f"@{PIN} # v7.0.1", "@v7"),
            valid.replace("  retention-days: 14", ""),
            valid.replace("retention-days: 14", "retention-days: 7"),
            valid + "\n  archive: false",
        )
        for block in invalid:
            with self.subTest(block=block):
                with self.assertRaises(AssertionError):
                    validate_upload_block(block)

    def test_chromium_failure_artifact_and_mariadb_ghcr_contract(self) -> None:
        ci = CI.read_text(encoding="utf-8")
        screenshot = (
            ROOT / "tests/e2e/public-concept05-fidelity-matrix.spec.mjs"
        ).read_text(encoding="utf-8")
        image = "image: ghcr.io/mariadb/mariadb:11.4.13-noble"
        self.assertEqual(3, ci.count(image))
        self.assertNotIn("image: mariadb:11.4", ci)

        self.assertEqual(2, ci.count("uses: actions/upload-artifact@"))
        block = upload_block(
            ci,
            target_name="name: concept05-pr-visual-${{ github.event.pull_request.head.sha || github.sha }}-${{ github.run_id }}",
        )
        validate_upload_block(block)
        self.assertIn("if: failure()", block)
        self.assertIn("path: test-results/public-concept05-fidelity-*/*", block)
        self.assertIn("if-no-files-found: warn", block)
        self.assertIn("test.info().attach('concept05-1440-fullpage'", screenshot)
        # Preserve fail-closed fingerprints while supplying both A/B viewports
        # and the committed owner-approved PNG in the same CI artifact.
        self.assertIn("test.info().attach('concept05-390-fullpage'", screenshot)
        self.assertIn("viewport.width === 390", screenshot)
        self.assertIn("test.info().attach('concept05-owner-reference-v2'", screenshot)
        # A body-only attachment can remain in the reporter instead of the
        # failure-artifact ZIP: require a persisted test-results PNG.
        self.assertIn("test.info().outputPath('concept05-390-fullpage.png')", screenshot)
        self.assertIn("test.info().outputPath('concept05-1440-fullpage.png')", screenshot)
        self.assertIn("page.screenshot({path:screenshotPath, fullPage:true", screenshot)
        self.assertIn("path:screenshotPath", screenshot)
        self.assertIn("path:'docs/reference/home-concept05-owner-reference-v2.png'", screenshot)
        self.assertIn("if (fingerprintMismatch)", screenshot)
        self.assertIn("viewport.width === 1440", screenshot)
        self.assertIn("fullPage:true", screenshot)
        self.assertIn("actual.structure !== expected.structure", screenshot)
        self.assertIn("actual.color !== expected.color", screenshot)
        # Critical: capture is not a replacement for the existing assertion.
        self.assertIn(").toEqual(expected)", screenshot)

    def test_fast_gate_executes_upload_artifact_v7_regression(self) -> None:
        ci = CI.read_text(encoding="utf-8")
        self.assertIn("Test CI resilience tooling", ci)
        self.assertIn("python3 -m unittest", ci)
        self.assertIn("tests/test_upload_artifact_v7_adoption.py", ci)


if __name__ == "__main__":
    unittest.main()
