#!/usr/bin/env python3
"""Fail-closed public preview popup and type-authority contracts."""

from __future__ import annotations

import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
ADMIN = (ROOT / "discadmin/public-preview.js").read_text(encoding="utf-8")
CONFIG = (ROOT / "config/public_preview.php").read_text(encoding="utf-8")
E2E = (ROOT / "tests/e2e/discadmin-public-preview.spec.mjs").read_text(encoding="utf-8")


class PublicPreviewPopupFailClosedTests(unittest.TestCase):
    def test_blocked_popup_keeps_discadmin_location_and_reports_failure(self) -> None:
        self.assertNotIn("window.location.assign", ADMIN)
        self.assertIn("const error = new Error('PREVIEW_POPUP_BLOCKED')", ADMIN)
        self.assertIn("reportPreviewError(error)", ADMIN)
        self.assertIn("if (!tab)", ADMIN)
        self.assertLess(ADMIN.index("if (!tab)"), ADMIN.index("const data = await create(type, payload)"))
        self.assertIn("blocked popup keeps DISCADMIN in place and does not create a snapshot", E2E)
        self.assertIn("expect(page.url()).toBe(originalUrl)", E2E)
        self.assertIn("window.__previewRequest || null", E2E)

    def test_allowed_popup_clears_opener_and_replaces_private_preview_location(self) -> None:
        self.assertIn("window.open('about:blank', '_blank')", ADMIN)
        self.assertIn("tab.opener = null", ADMIN)
        self.assertIn("tab.location.replace(data.url)", ADMIN)
        self.assertNotIn("'noopener'", ADMIN)
        self.assertIn("url:'about:blank'", E2E)
        self.assertIn("window.__previewOpened", E2E)

    def test_client_does_not_duplicate_server_canonical_type_allowlist(self) -> None:
        canonical_server = "['events', 'artists', 'sets', 'releases', 'blog', 'pages']"
        canonical_client = "['events','artists','sets','releases','blog','pages']"
        self.assertIn(canonical_server, CONFIG)
        self.assertIn("brvtalPublicPreviewTypes()", CONFIG)
        self.assertIn("PREVIEW_TYPE_NOT_ALLOWED", CONFIG)
        self.assertNotIn(canonical_client, ADMIN)
        self.assertNotIn("canonicalTypes", ADMIN)
        self.assertNotIn("PREVIEW_TYPE_NOT_ALLOWED", ADMIN)
        self.assertIn("previewableLegacyTypes", ADMIN)
        self.assertIn("['events','artists','sets','pages']", ADMIN)
        self.assertIn("server remains the canonical authority for unsupported preview types", E2E)
        self.assertIn("PREVIEW_TYPE_NOT_ALLOWED", E2E)


if __name__ == "__main__":
    unittest.main()
