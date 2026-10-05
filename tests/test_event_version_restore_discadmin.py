#!/usr/bin/env python3
"""Contrato de Event Version Restore Editor V1 en DISCADMIN."""

from __future__ import annotations

import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
CORE = ROOT / "discadmin" / "content-core.js"
ACTIVITY = ROOT / "discadmin" / "admin-activity.js"
PLANNER = ROOT / "discadmin" / "event-version-restore.js"


class EventVersionRestoreDiscadminTests(unittest.TestCase):
    def core(self) -> str:
        return CORE.read_text(encoding="utf-8")

    def activity(self) -> str:
        return ACTIVITY.read_text(encoding="utf-8")

    def stage_function(self) -> str:
        core = self.core()
        start = core.index("async function stageEventVersionRestore(")
        end = core.index("\nasync function openEventHistory()", start)
        return core[start:end]

    def apply_function(self) -> str:
        core = self.core()
        start = core.index("function applyEventRestorePlan(")
        end = core.index("\nasync function stageEventVersionRestore(", start)
        return core[start:end]

    def test_history_can_load_safe_version_into_open_event_editor_with_confirmation(self) -> None:
        core = self.core()
        activity = self.activity()

        self.assertIn("LOAD INTO EDITOR", activity)
        self.assertIn("typeof restoreOptions.onRestore !== 'function'", activity)
        self.assertIn("Nothing is saved automatically.", activity)
        self.assertIn("restoreOptions.onRestore(item)", activity)
        self.assertIn("brvtalEventRestoreScriptSrc()", core)
        self.assertIn("/discadmin/event-version-restore.js", core)
        self.assertIn("restoreModule.buildPlan(item,eventRestoreEditorSnapshot())", core)
        self.assertIn("window.confirm(", core)
        self.assertIn("Nothing will be saved until you press SAVE EVENT.", core)
        self.assertIn("onRestore:item=>stageEventVersionRestore(item,eventId,restoreModule)", core)

        for path in (CORE, ACTIVITY, PLANNER):
            check = subprocess.run(
                ["node", "--check", str(path)],
                cwd=ROOT,
                check=False,
                text=True,
                capture_output=True,
                timeout=30,
            )
            self.assertEqual(check.returncode, 0, check.stderr)

    def test_restore_preserves_lifecycle_schedule_relations_and_marks_editor_dirty(self) -> None:
        core = self.core()
        apply = self.apply_function()

        self.assertIn("window.BRVTALUnsavedChanges?.touch?.(modal)", apply)
        self.assertIn("control.dispatchEvent(new Event('input',{bubbles:true}))", apply)
        self.assertIn("control.dispatchEvent(new Event('change',{bubbles:true}))", apply)
        self.assertIn("window.BRVTALAdminColorField?.sync?.($('#e_accent'))", apply)

        mapping_start = core.index("const EVENT_RESTORE_CONTROL_IDS=Object.freeze({")
        mapping_end = core.index("});", mapping_start)
        mapping = core[mapping_start:mapping_end]
        for allowed in (
            "title:'e_title'",
            "slug:'e_slug'",
            "event_date:'e_event_date'",
            "archive_year:'e_archive_year'",
            "venue:'e_venue'",
            "city:'e_city'",
            "description:'e_description'",
            "accent:'e_accent'",
            "cover_image:'e_cover_image'",
            "ticket_url:'e_ticket_url'",
            "ticket_instructions:'e_ticket_instructions'",
            "ticket_qr:'e_ticket_qr'",
            "featured:'e_featured'",
            "seo_title:'e_seo_title'",
            "seo_description:'e_seo_description'",
        ):
            self.assertIn(allowed, mapping)
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
            self.assertNotIn(forbidden, mapping)

        self.assertNotIn("saveEvent(", apply)
        self.assertNotIn("api(", apply)
        self.assertNotIn("fetch(", apply)

    def test_new_closed_changed_or_incompatible_event_fails_closed_without_save_or_fetch(self) -> None:
        stage = self.stage_function()
        core = self.core()
        activity = self.activity()

        self.assertIn("Number(currentEvent?.id||0)!==eventId", stage)
        self.assertIn("!modal?.classList.contains('open')", stage)
        self.assertIn("!eventRestorePlanReady(plan)", stage)
        self.assertIn("Event changed before the restore could be staged.", stage)
        self.assertNotIn("saveEvent(", stage)
        self.assertNotIn("api(", stage)
        self.assertNotIn("fetch(", stage)

        self.assertIn(
            "const restoreOptions = resource === 'events' && typeof options?.onRestore === 'function' ? options : null;",
            activity,
        )
        self.assertIn("restoreOptions?'review-before-save restore available':'read-only'", activity)
        self.assertIn("Version restore unavailable; history remains read-only.", core)
        self.assertIn("eventId<1||!modal?.classList.contains('open')", core)


if __name__ == "__main__":
    unittest.main()
