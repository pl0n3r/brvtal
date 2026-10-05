#!/usr/bin/env python3
"""Executable wiring contract for Event Version Restore real-stack coverage."""
from __future__ import annotations

from functools import lru_cache
import json
import os
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BROWSER_SPEC = "tests/e2e/discadmin-event-version-restore.spec.mjs"


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


@lru_cache(maxsize=1)
def listed_browser_tests() -> str:
    env = {
        **os.environ,
        "BRVTAL_REAL_STACK_URL": "http://127.0.0.1:4174",
        "BRVTAL_REAL_STACK_ADMIN_PASSWORD": "list-only-placeholder",
    }
    result = subprocess.run(
        [
            "npx",
            "playwright",
            "test",
            BROWSER_SPEC,
            "--project=chromium",
            "--list",
        ],
        cwd=ROOT,
        check=False,
        text=True,
        capture_output=True,
        timeout=30,
        env=env,
    )
    if result.returncode != 0:
        raise AssertionError(result.stderr or result.stdout)
    return result.stdout


class EventVersionRestoreE2ETests(unittest.TestCase):
    def test_staged_restore_saves_through_canonical_event_path_and_creates_new_history(self) -> None:
        listed = listed_browser_tests()
        runner = read("tests/e2e/run-content-core-real-stack.sh")
        workflow = read(".github/workflows/update-release-metadata.yml")

        self.assertIn(
            "staged restore saves through the canonical Event workflow and creates new history",
            listed,
        )
        self.assertIn(BROWSER_SPEC, runner)
        self.assertIn("bash tests/e2e/run-content-core-real-stack.sh", workflow)

    def test_new_stale_or_wrong_event_restore_fails_closed_without_mutation(self) -> None:
        self.assertIn(
            "new, wrong, stale, closed, incompatible and empty restores fail closed without mutation",
            listed_browser_tests(),
        )

    def test_restore_never_rewinds_lifecycle_or_relations_and_cancel_discards_staged_changes(self) -> None:
        self.assertIn(
            "cancel discards a staged restore while lifecycle and relations remain current",
            listed_browser_tests(),
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
        self.assertEqual(match.group(1), "0.1.124")
        self.assertEqual(package["version"], "0.1.124")
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
