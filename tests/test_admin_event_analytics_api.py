#!/usr/bin/env python3
"""Contrato del endpoint Admin read-only para Event Insights."""

from __future__ import annotations

import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
API = ROOT / "api" / "admin-event-analytics.php"
SIGNALS = ROOT / "config" / "event_analytics_signals.php"


class AdminEventAnalyticsApiTests(unittest.TestCase):
    def api(self) -> str:
        return API.read_text(encoding="utf-8")

    def signals(self) -> str:
        return SIGNALS.read_text(encoding="utf-8")

    def test_endpoint_is_authenticated_get_only_no_store_and_returns_bounded_event_summary(self) -> None:
        api = self.api()

        lint = subprocess.run(
            ["php", "-l", str(API)],
            cwd=ROOT,
            check=False,
            text=True,
            capture_output=True,
            timeout=30,
        )
        self.assertEqual(lint.returncode, 0, lint.stderr)

        self.assertIn("require_once __DIR__ . '/../config/admin_auth.php';", api)
        self.assertIn("require_once __DIR__ . '/../config/event_analytics_signals.php';", api)
        self.assertIn("brvtal_admin_require();", api)
        self.assertIn("brvtalEventAnalyticsSignals($event)", api)
        self.assertIn("'Cache-Control'=>'no-store'", api)
        self.assertIn("'SELECT id, slug, status, event_date, published_at FROM events WHERE id = ? LIMIT 1'", api)
        self.assertNotIn("SELECT *", api)

    def test_invalid_method_range_or_nonpublic_event_fails_closed_without_external_query(self) -> None:
        api = self.api()
        signals = self.signals()

        method_guard = api.index("METHOD_NOT_ALLOWED")
        parameter_guard = api.index("INVALID_PARAMETER")
        range_guard = api.index("INVALID_WINDOW")
        external_call = api.index("brvtalEventAnalyticsSignals($event)")

        self.assertLess(method_guard, external_call)
        self.assertLess(parameter_guard, external_call)
        self.assertLess(range_guard, external_call)
        self.assertIn("array_keys($_GET)", api)
        self.assertIn("['id', 'window']", api)
        self.assertIn("$window !== '7d'", api)
        self.assertIn("EVENT_NOT_FOUND", api)
        self.assertIn("brvtal_public_event_is_visible($event)", signals)
        self.assertLess(
            signals.index("brvtal_public_event_is_visible($event)"),
            signals.index("$accessToken = brvtalAnalyticsAccessToken"),
        )

    def test_endpoint_never_exposes_secret_token_raw_ga4_or_visitor_identifiers(self) -> None:
        api = self.api()
        selected = "SELECT id, slug, status, event_date, published_at"

        self.assertIn(selected, api)
        for forbidden in (
            "access_token",
            "private_key",
            "client_email",
            "visitor_id",
            "session_id",
            "ip_address",
            "user_pseudo_id",
            "raw_ga4",
        ):
            self.assertNotIn(forbidden, api.lower())
        self.assertNotIn("$_POST", api)
        self.assertNotIn("brvtal_admin_require_csrf", api)

    def test_fresh_stale_not_configured_and_unavailable_states_remain_explicit(self) -> None:
        api = self.api()
        signals = self.signals()

        self.assertIn("brvtalEventAnalyticsSignals($event)", api)
        for state in ("FRESH", "STALE", "NOT CONFIGURED", "UNAVAILABLE"):
            self.assertIn(state, signals)
        self.assertIn("'metrics'=>['users'=>null, 'sessions'=>null, 'views'=>null]", signals)
        self.assertNotIn("'metrics'=>['users'=>0", signals)


if __name__ == "__main__":
    unittest.main()
