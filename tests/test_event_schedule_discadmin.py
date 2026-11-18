#!/usr/bin/env python3
"""Contrato del control DISCADMIN para programación de publicación de Events."""

from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
CONTENT = ROOT / "discadmin" / "content-core.php"
CORE_JS = ROOT / "discadmin" / "content-core.js"
VERSION = ROOT / "config" / "version.php"
PACKAGE = ROOT / "package.json"


class EventScheduleDiscadminTests(unittest.TestCase):
    def content(self) -> str:
        return CONTENT.read_text(encoding="utf-8")

    def core_js(self) -> str:
        return CORE_JS.read_text(encoding="utf-8")

    def run_node(self, source: str) -> str:
        result = subprocess.run(
            ["node", "-e", source, str(CORE_JS)],
            cwd=ROOT,
            check=False,
            text=True,
            capture_output=True,
            timeout=30,
        )
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        return result.stdout.strip()

    def test_lifecycle_step_exposes_accessible_publish_not_before_without_seventh_step(self) -> None:
        html = self.content()

        self.assertIn('id="e_publish_schedule_field"', html)
        self.assertIn('label for="e_publish_at">Publish not before</label>', html)
        self.assertIn('id="e_publish_at"', html)
        self.assertIn('type="datetime-local"', html)
        self.assertIn('aria-describedby="e_publish_at_help"', html)
        self.assertIn('id="e_publish_at_help"', html)
        self.assertIn('role="status"', html)
        self.assertEqual(html.count('data-step="'), 6)
        self.assertNotIn('data-step="7"', html)
        self.assertNotIn('data-content="7"', html)

    def test_editor_serializes_publish_at_never_published_at(self) -> None:
        core = self.core_js()

        self.assertIn("const rawPublishAt=$('#e_publish_at')?.value||'';", core)
        self.assertIn(
            "const publishAt=eventPublishScheduleEnabled()&&rawPublishAt?rawPublishAt.replace('T',' '):null;",
            core,
        )
        self.assertIn("status:$('#e_status').value,publish_at:publishAt", core)
        self.assertNotIn("published_at:publishAt", core)
        self.assertNotIn("published_at:rawPublishAt", core)

    def test_future_published_at_hydrates_schedule_and_blank_means_publish_now(self) -> None:
        core = self.core_js()
        start = core.index("const EVENT_PUBLIC_SCHEDULE_STATUSES")
        end = core.index("\nfunction openEvent(", start)
        helpers = core[start:end]

        harness = r"""
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync(process.argv[1],'utf8');
const start=source.indexOf('const EVENT_PUBLIC_SCHEDULE_STATUSES');
const end=source.indexOf('\nfunction openEvent(',start);
const helpers=source.slice(start,end);
const context={Date,Set,Number,String};
vm.createContext(context);
vm.runInContext(helpers,context);
const now=Date.parse('2026-10-04T18:00:00');
const future=context.eventPublishScheduleInputValue({published_at:'2026-10-04 19:30:00'},now);
const past=context.eventPublishScheduleInputValue({published_at:'2026-10-04 17:30:00'},now);
console.log(JSON.stringify({future,past}));
"""
        self.assertIn("eventPublishScheduleInputValue(currentEvent)", core)
        self.assertIn("publish_at:publishAt", core)
        self.assertIn(":null;", core)
        result = json.loads(self.run_node(harness))
        self.assertEqual(result, {"future": "2026-10-04T19:30", "past": ""})

    def test_non_public_status_disables_schedule_without_breaking_save_preview_history_or_insights(self) -> None:
        core = self.core_js()

        self.assertIn(
            "new Set(['published','upcoming','tickets_available','last_tickets','sold_out'])",
            core,
        )
        self.assertIn("input.disabled=!enabled", core)
        self.assertIn("input.setAttribute('aria-disabled',enabled?'false':'true')", core)
        self.assertIn("Draft and historical states cannot be scheduled.", core)
        self.assertIn("$('#e_status')?.addEventListener('change',syncEventPublishScheduleControl);", core)
        self.assertIn("openEventHistory", core)
        self.assertIn("previewEvent", core)
        self.assertIn("refreshEventInsights", core)
        self.assertIn("window.BRVTALUnsavedChanges?.markClean", core)

        check = subprocess.run(
            ["node", "--check", str(CORE_JS)],
            cwd=ROOT,
            check=False,
            text=True,
            capture_output=True,
            timeout=30,
        )
        self.assertEqual(check.returncode, 0, check.stderr)

        version = VERSION.read_text(encoding="utf-8")
        package = json.loads(PACKAGE.read_text(encoding="utf-8"))
        release_line = next(
            line for line in version.splitlines()
            if line.startswith("const BRVTAL_APP_VERSION = ")
        )
        release_version = release_line.split("'")[1]
        self.assertGreaterEqual(
            tuple(int(part) for part in release_version.split(".")),
            (0, 1, 120),
        )
        self.assertEqual(package["version"], release_version)
        self.assertEqual(
            package["scripts"]["test:event-schedule-discadmin"],
            "python3 -m unittest tests/test_event_schedule_discadmin.py",
        )
        self.assertIn(
            "npm run test:event-schedule-discadmin",
            package["scripts"]["test:integration"],
        )


if __name__ == "__main__":
    unittest.main()
