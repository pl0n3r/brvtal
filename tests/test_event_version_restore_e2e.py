#!/usr/bin/env python3
"""Wiring contract for Event Version Restore real-stack browser coverage."""
from __future__ import annotations

import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BROWSER_SPEC = "tests/e2e/discadmin-event-version-restore.spec.mjs"


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def browser_test_titles() -> set[str]:
    source = read(BROWSER_SPEC)
    return set(re.findall(r"\btest\(\s*['\"]([^'\"]+)['\"]", source))


def assert_real_stack_wiring(testcase: unittest.TestCase) -> None:
    runner = read("tests/e2e/run-content-core-real-stack.sh")
    workflow = read(".github/workflows/update-release-metadata.yml")

    runner_specs = {
        line.strip().rstrip("\\").strip()
        for line in runner.splitlines()
        if line.strip().startswith("tests/e2e/") and line.strip().endswith((".mjs \\", ".mjs"))
    }
    testcase.assertIn(BROWSER_SPEC, runner_specs)
    testcase.assertRegex(
        workflow,
        r"run:\s+bash tests/e2e/run-content-core-real-stack\.sh",
    )


class EventVersionRestoreE2ETests(unittest.TestCase):
    def test_staged_restore_saves_through_canonical_event_path_and_creates_new_history(self) -> None:
        assert_real_stack_wiring(self)
        self.assertIn(
            "staged restore saves through the canonical Event workflow and creates new history",
            browser_test_titles(),
        )

    def test_new_stale_or_wrong_event_restore_fails_closed_without_mutation(self) -> None:
        assert_real_stack_wiring(self)
        self.assertIn(
            "new, wrong, stale, closed, incompatible and empty restores fail closed without mutation",
            browser_test_titles(),
        )

    def test_restore_never_rewinds_lifecycle_or_relations_and_cancel_discards_staged_changes(self) -> None:
        assert_real_stack_wiring(self)
        self.assertIn(
            "cancel discards a staged restore while lifecycle and relations remain current",
            browser_test_titles(),
        )

    def test_spec_declares_review_before_save_restore_contract(self) -> None:
        spec = read("docs/BRVTAL-SPEC.md")
        package = json.loads(read("package.json"))
        version = read("config/version.php")

        self.assertIn("review-before-save", spec)
        self.assertIn("Admin Activity dashboard remains read-only", spec)
        self.assertIn("LOAD INTO EDITOR", spec)
        self.assertIn("does not persist anything until the normal Save", spec)
        self.assertIn(
            "lifecycle/status, server-owned timestamps, tickets, lineup and timetable",
            spec,
        )
        self.assertIn("Cancel or close before Save discards the staged restore", spec)

        match = re.search(r"BRVTAL_APP_VERSION\s*=\s*'([^']+)'", version)
        self.assertIsNotNone(match)
        release_version = match.group(1)
        self.assertGreaterEqual(tuple(map(int, release_version.split("."))), (0, 1, 124))
        self.assertEqual(package["version"], release_version)
        self.assertEqual(
            package["scripts"]["test:event-version-restore-e2e"],
            "python3 -m unittest tests/test_event_version_restore_e2e.py",
        )
        self.assertIn(
            "npm run test:event-version-restore-e2e",
            package["scripts"]["test:integration"],
        )


if __name__ == "__main__":
    unittest.main()
