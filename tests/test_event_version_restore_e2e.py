#!/usr/bin/env python3
"""Closeout contract for Event Version Restore review-before-save behavior."""
from __future__ import annotations

import json
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def run_checked(command: list[str]) -> None:
    subprocess.run(
        command,
        cwd=ROOT,
        check=True,
        capture_output=True,
        text=True,
        timeout=30,
    )


class EventVersionRestoreE2ETests(unittest.TestCase):
    def test_staged_restore_saves_through_canonical_event_path_and_creates_new_history(self) -> None:
        browser = read("tests/e2e/discadmin-event-version-restore.spec.mjs")
        core = read("discadmin/content-core.js")
        api = read("api/index.php")

        self.assertIn(
            "stages an older Event version without persisting until canonical Save and records new history",
            browser,
        )
        self.assertIn("expect(putBodies).toHaveLength(0)", browser)
        self.assertIn("expect(historyItems).toHaveLength(3)", browser)
        self.assertIn("request().method()).toBe('PUT')", browser)
        self.assertIn(
            "const path=currentEvent?'/events/'+currentEvent.id:'/events';",
            core,
        )
        self.assertIn(
            "method:currentEvent?'PUT':'POST'",
            core,
        )
        self.assertIn(
            "brvtal_activity_record($pdo,'update',$resource,$id,$before,$after,['source'=>'core_api'])",
            api,
        )
        run_checked(["node", "--check", "tests/e2e/discadmin-event-version-restore.spec.mjs"])

    def test_new_stale_or_wrong_event_restore_fails_closed_without_mutation(self) -> None:
        browser = read("tests/e2e/discadmin-event-version-restore.spec.mjs")
        core = read("discadmin/content-core.js")
        planner = read("discadmin/event-version-restore.js")

        self.assertIn(
            "wrong, stale, closed, incompatible and empty restore plans fail closed without mutation",
            browser,
        )
        self.assertIn("wrong:false", browser)
        self.assertIn("stale:false", browser)
        self.assertIn("closed:false", browser)
        self.assertIn("fresh:false", browser)
        self.assertIn("INCOMPATIBLE_SNAPSHOT", browser)
        self.assertIn("NO_SAFE_CHANGES", browser)
        self.assertIn(
            "if(Number(currentEvent?.id||0)!==eventId||!modal?.classList.contains('open'))return false;",
            core,
        )
        self.assertIn(
            "if(Number(currentEvent?.id||0)!==eventId||!modal.classList.contains('open'))return false;",
            core,
        )
        self.assertIn("if (!eventId || !resourceId || eventId !== resourceId || !activityId)", planner)

    def test_restore_never_rewinds_lifecycle_or_relations_and_cancel_discards_staged_changes(self) -> None:
        browser = read("tests/e2e/discadmin-event-version-restore.spec.mjs")
        planner = read("discadmin/event-version-restore.js")
        core = read("discadmin/content-core.js")

        self.assertIn(
            "cancel discards a staged restore while lifecycle and relations stay current",
            browser,
        )
        self.assertIn("toHaveValue('sold_out')", browser)
        self.assertIn("toContainText('VIP')", browser)
        self.assertIn("toContainText('HEADLINER')", browser)
        self.assertIn("toContainText('PL0N3R')", browser)
        self.assertIn("window.__reopen()", browser)
        self.assertIn("expect(putBodies).toHaveLength(0)", browser)

        restore_fields = planner[
            planner.index("const RESTORABLE_FIELDS"):
            planner.index("]);", planner.index("const RESTORABLE_FIELDS")) + 3
        ]
        for forbidden in (
            "status",
            "publish_at",
            "published_at",
            "cancelled_at",
            "finished_at",
            "ticket_types",
            "lineup",
            "timetable",
        ):
            self.assertNotIn(f"'{forbidden}'", restore_fields)

        mapping = core[
            core.index("const EVENT_RESTORE_CONTROL_IDS=Object.freeze({"):
            core.index("});", core.index("const EVENT_RESTORE_CONTROL_IDS=Object.freeze({"))
        ]
        for forbidden in ("status", "publish_at", "ticket_types", "lineup", "timetable"):
            self.assertNotIn(forbidden, mapping)

    def test_spec_declares_review_before_save_restore_contract(self) -> None:
        spec = read("docs/BRVTAL-SPEC.md")
        package = json.loads(read("package.json"))
        version = read("config/version.php")

        self.assertIn("review-before-save", spec)
        self.assertIn("Admin Activity dashboard remains read-only", spec)
        self.assertIn("LOAD INTO EDITOR", spec)
        self.assertIn("does not persist anything until the normal Save", spec)
        self.assertIn("lifecycle/status, server-owned timestamps, tickets, lineup and timetable", spec)
        self.assertIn("Cancel or close before Save discards the staged restore", spec)

        match = re.search(r"BRVTAL_APP_VERSION\s*=\s*'([^']+)'", version)
        self.assertIsNotNone(match)
        self.assertEqual(match.group(1), "0.1.124")
        self.assertEqual(package["version"], "0.1.124")
        self.assertEqual(
            package["scripts"]["test:event-version-restore-e2e"],
            "python3 -m unittest tests/test_event_version_restore_e2e.py",
        )
        self.assertIn("npm run test:event-version-restore-e2e", package["scripts"]["test:integration"])


if __name__ == "__main__":
    unittest.main()
