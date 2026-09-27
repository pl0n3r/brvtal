#!/usr/bin/env python3
"""Contract tests for BRVTAL adoption of Factory v1 coordination."""

from __future__ import annotations

import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/work-coordination.yml"
CI = ROOT / ".github/workflows/update-release-metadata.yml"
LOCAL_COORDINATOR = ROOT / "scripts/work_coordinator.py"
LEGACY_TESTS = ROOT / "tests/test_work_coordinator.py"


class FactoryCoordinationAdoptionTests(unittest.TestCase):
    def workflow_text(self) -> str:
        """Return the repository coordination workflow as contract text."""
        return WORKFLOW.read_text(encoding="utf-8")

    def ci_text(self) -> str:
        """Return the main BRVTAL CI workflow as contract text."""
        return CI.read_text(encoding="utf-8")

    def test_all_operations_use_factory_v1(self) -> None:
        """All coordination entry points must resolve through Factory v1."""
        workflow = self.workflow_text()
        ci = self.ci_text()

        self.assertGreaterEqual(
            workflow.count("uses: pl0n3r/factory/.github/workflows/coordinacion.yml@v1"),
            5,
        )
        for operation in ("comment", "label", "pr", "issue", "sweep"):
            self.assertRegex(
                workflow,
                rf"operation:\s*{operation}\b",
                msg=f"missing Factory v1 operation {operation}",
            )
        self.assertIn("profile: en", workflow)

        self.assertIn(
            "uses: pl0n3r/factory/.github/workflows/coordinacion.yml@v1",
            ci,
        )
        self.assertRegex(ci, r"operation:\s*validate\b")
        self.assertIn("profile: en", ci)

    def test_recovery_and_validation_semantics_are_preserved(self) -> None:
        """CI must execute published Factory behaviors for recovery and validation."""
        workflow = self.workflow_text()
        ci = self.ci_text()

        for command in (
            "/take",
            "/force-release",
            "/release ",
            "/transfer ",
            "/recover ",
        ):
            self.assertIn(command, workflow)

        self.assertIn("group: brvtal-work-coordination", workflow)
        self.assertIn("cancel-in-progress: false", workflow)
        self.assertIn("queue: max", workflow)
        self.assertIn("operation: sweep", workflow)
        self.assertIn(
            "require_reservation: ${{ github.event.pull_request.number != 728 }}",
            ci,
        )
        self.assertNotIn("require_reservation: false", ci)
        self.assertIn("Bootstrap-only exception: PR #728", ci)

        self.assertIn("Checkout published Factory v1 behavior harness", ci)
        for behavior in (
            "test_reserve_work_creates_atomic_lock_and_session",
            "test_stale_reservation_reuses_existing_branch_and_pr",
            "test_validate_pull_checks_active_session",
            "test_validate_pull_rejects_open_pr_overlap",
            "test_english_profile_supports_brvtal_coordination_contract",
            "test_english_profile_reads_brvtal_legacy_reservations",
            "test_profiles_fail_closed_on_authority_and_collision_errors",
        ):
            self.assertIn(behavior, ci)

    def test_events_and_permissions_are_bounded(self) -> None:
        """Callers must preserve event bounds and the reusable permission envelope."""
        workflow = self.workflow_text()
        ci = self.ci_text()

        self.assertIn("github.event.issue.pull_request == null", workflow)
        self.assertIn(
            "github.event.pull_request.head.repo.full_name == github.repository",
            workflow,
        )
        self.assertNotIn("pull_request_target:", workflow)

        # GitHub validates the reusable workflow's maximum permission envelope at load
        # time. Factory v1 currently requires checks:write in every caller even when
        # the selected operation itself remains read-only internally.
        caller_count = workflow.count(
            "uses: pl0n3r/factory/.github/workflows/coordinacion.yml@v1"
        )
        self.assertEqual(workflow.count("checks: write"), caller_count)
        self.assertIn("checks: write", ci)

        validate_block = re.search(
            r"coordination-pr:.*?(?=\n  [a-zA-Z0-9_-]+:|\Z)",
            ci,
            flags=re.S,
        )
        self.assertIsNotNone(validate_block)
        assert validate_block is not None
        # Factory v1 must receive its declared maximum workflow_call envelope.
        # Effective permissions remain narrowed inside the selected reusable job.
        self.assertIn("contents: write", validate_block.group(0))
        self.assertIn("issues: write", validate_block.group(0))
        self.assertIn("pull-requests: write", validate_block.group(0))
        self.assertIn("checks: write", validate_block.group(0))

    def test_no_local_duplicate_authority_remains(self) -> None:
        """Local coordinator code must disappear once published parity is proven."""
        self.assertFalse(
            LOCAL_COORDINATOR.exists(),
            "local coordinator must be removed after Factory v1 parity",
        )
        self.assertFalse(
            LEGACY_TESTS.exists(),
            "legacy local-coordinator tests must not remain as a second authority",
        )

        combined = self.workflow_text() + "\n" + self.ci_text()
        self.assertNotIn("python3 scripts/work_coordinator.py", combined)
        self.assertNotIn("tests/test_work_coordinator.py", combined)


if __name__ == "__main__":
    unittest.main()
