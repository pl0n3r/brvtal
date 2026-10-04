#!/usr/bin/env python3
"""Contrato del panel Event Insights integrado al editor canónico de Events."""

from __future__ import annotations

import re
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
CONTENT = ROOT / "discadmin" / "content-core.php"
CORE_JS = ROOT / "discadmin" / "content-core.js"
INSIGHTS_JS = ROOT / "discadmin" / "event-insights.js"


class EventInsightsDiscadminTests(unittest.TestCase):
    def content(self) -> str:
        return CONTENT.read_text(encoding="utf-8")

    def core_js(self) -> str:
        return CORE_JS.read_text(encoding="utf-8")

    def insights_js(self) -> str:
        return INSIGHTS_JS.read_text(encoding="utf-8")

    def test_event_editor_renders_read_only_bounded_insights_from_canonical_endpoint(self) -> None:
        html = self.content()
        core = self.core_js()
        insights = self.insights_js()

        for path in (CORE_JS, INSIGHTS_JS):
            check = subprocess.run(
                ["node", "--check", str(path)],
                cwd=ROOT,
                check=False,
                text=True,
                capture_output=True,
                timeout=30,
            )
            self.assertEqual(check.returncode, 0, check.stderr)

        self.assertIn('data-step="7">07 · INSIGHTS', html)
        self.assertIn('data-event-insights-panel', html)
        self.assertIn('id="eventInsightsWindow"', html)
        for metric in ("Users", "Sessions", "Views"):
            self.assertIn(f'id="eventInsights{metric}"', html)
            self.assertIn(f'id="eventInsights{metric}Previous"', html)

        self.assertIn("script.src='/discadmin/event-insights.js'", core)
        self.assertIn("refreshEventInsights(currentEvent,insightsRequestId)", core)
        self.assertIn("'/api/admin-event-analytics.php?id='", insights)
        self.assertIn("method:'GET'", insights)
        self.assertIn("credentials:'same-origin'", insights)
        self.assertIn("cache:'no-store'", insights)
        self.assertNotIn("method:'POST'", insights)

    def test_draft_private_or_unavailable_event_never_queries_or_renders_fake_zero_metrics(self) -> None:
        insights = self.insights_js()

        match = re.search(
            r"PUBLIC_EVENT_STATUSES=new Set\(\[(?P<body>.*?)\]\);",
            insights,
            flags=re.DOTALL,
        )
        self.assertIsNotNone(match)
        allowlist = match.group("body")
        self.assertNotIn("'draft'", allowlist)
        self.assertNotIn("'private'", allowlist)

        guard = insights.index("if(!isPotentiallyPublic(event))")
        fetch_call = insights.index("const response=await fetch(")
        self.assertLess(guard, fetch_call)
        self.assertIn("No analytics request was sent.", insights)
        self.assertIn("resetMetrics();", insights)
        self.assertIn("textContent='—'", insights)
        self.assertNotRegex(insights, r"textContent\s*=\s*['\"]0['\"]")
        self.assertNotIn("innerHTML", insights)
        self.assertNotIn("csrf", insights.lower())

    def test_loading_fresh_stale_not_configured_and_unavailable_states_are_accessible_and_distinct(self) -> None:
        html = self.content()
        insights = self.insights_js()

        self.assertIn('role="status"', html)
        self.assertIn('aria-live="polite"', html)
        for state in (
            "LOADING",
            "FRESH",
            "STALE",
            "NOT CONFIGURED",
            "UNAVAILABLE",
            "NOT PUBLISHED",
        ):
            self.assertIn(state, insights)
        self.assertIn("Cached metrics; source is stale.", insights)
        self.assertIn("Analytics is not configured.", insights)
        self.assertIn("Analytics is temporarily unavailable.", insights)

    def test_panel_preserves_existing_event_workflow_navigation_and_editor_contracts(self) -> None:
        html = self.content()
        core = self.core_js()

        for step in (
            "01 · IDENTITY",
            "02 · DATE & PLACE",
            "03 · LIFECYCLE",
            "04 · TICKETS",
            "05 · ROSTER",
            "06 · TIMETABLE",
            "07 · INSIGHTS",
        ):
            self.assertIn(step, html)

        for invariant in (
            "eventWizardLastStep()",
            "saveEvent=async function()",
            "previewEvent",
            "eventTimetable",
            "eventArtists",
            "tickets",
            "BRVTALContentCoreLineup",
        ):
            self.assertIn(invariant, core)

        self.assertIn("window.BRVTALEventInsights", core)
        self.assertIn("Object.assign(window.BRVTALContentCore", core)
        self.assertNotIn("window.BRVTALContentCore =", self.insights_js())


if __name__ == "__main__":
    unittest.main()
