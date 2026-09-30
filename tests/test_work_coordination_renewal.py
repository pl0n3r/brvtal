#!/usr/bin/env python3
"""Regression coverage for BRVTAL coordination command routing."""

from __future__ import annotations

import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/work-coordination.yml"


class WorkCoordinationRenewalTests(unittest.TestCase):
    def workflow_text(self) -> str:
        return WORKFLOW.read_text(encoding="utf-8")

    def command_condition(self) -> str:
        workflow = self.workflow_text()
        match = re.search(
            r"  command:\n.*?\n    if: >-\n(?P<condition>.*?)(?=\n    # Factory v1 declares)",
            workflow,
            flags=re.S,
        )
        self.assertIsNotNone(match)
        assert match is not None
        return match.group("condition")

    def test_caller_routes_contract_renewal_without_weakening_filters(self) -> None:
        condition = self.command_condition()

        self.assertIn("github.event_name == 'issue_comment'", condition)
        self.assertIn("github.event.issue.pull_request == null", condition)
        self.assertIn("github.event.sender.login != 'github-actions[bot]'", condition)
        self.assertIn(
            "startsWith(github.event.comment.body, '/renovar-contrato ')",
            condition,
        )

    def test_command_allowlist_preserves_existing_commands_and_rejects_arbitrary_comments(self) -> None:
        condition = self.command_condition()

        for command in (
            "github.event.comment.body == '/take'",
            "github.event.comment.body == '/force-release'",
            "startsWith(github.event.comment.body, '/release ')",
            "startsWith(github.event.comment.body, '/transfer ')",
            "startsWith(github.event.comment.body, '/recover ')",
            "startsWith(github.event.comment.body, '/renovar-contrato ')",
        ):
            self.assertIn(command, condition)

        self.assertNotIn("startsWith(github.event.comment.body, '/')", condition)
        self.assertNotIn("contains(github.event.comment.body", condition)
        self.assertNotIn("github.event.comment.body != ''", condition)


if __name__ == "__main__":
    unittest.main()
