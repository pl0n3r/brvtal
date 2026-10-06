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


def upload_block(text: str) -> str:
    lines = text.splitlines()
    matches = [index for index, line in enumerate(lines) if "uses: actions/upload-artifact@" in line]
    if len(matches) != 1:
        raise AssertionError(f"expected exactly one upload-artifact use, found {len(matches)}")
    index = matches[0]
    return "\n".join(lines[max(0, index - 2): index + 12])


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
        for path in TARGETS:
            with self.subTest(path=path.name):
                text = path.read_text(encoding="utf-8")
                block = upload_block(text)
                validate_upload_block(block)
                self.assertNotIn(f"actions/upload-artifact@{LEGACY_PIN}", text)
                self.assertNotIn("actions/upload-artifact@v4", text)
                self.assertNotIn("actions/upload-artifact@v7", text)

    def test_upload_contract_preserves_paths_conditions_and_fourteen_day_retention(self) -> None:
        for path, expected in TARGETS.items():
            with self.subTest(path=path.name):
                text = path.read_text(encoding="utf-8")
                block = upload_block(text)
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

    def test_fast_gate_executes_upload_artifact_v7_regression(self) -> None:
        ci = CI.read_text(encoding="utf-8")
        self.assertIn("Test CI resilience tooling", ci)
        self.assertIn("python3 -m unittest", ci)
        self.assertIn("tests/test_upload_artifact_v7_adoption.py", ci)


if __name__ == "__main__":
    unittest.main()
