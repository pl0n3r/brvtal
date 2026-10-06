#!/usr/bin/env python3
"""Concept 05 private draft preview alignment contracts."""

from __future__ import annotations

import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
API = (ROOT / "api/public-preview.php").read_text(encoding="utf-8")
CONFIG = (ROOT / "config/public_preview.php").read_text(encoding="utf-8")
PREVIEW = (ROOT / "preview.php").read_text(encoding="utf-8")
ADMIN = (ROOT / "discadmin/public-preview.js").read_text(encoding="utf-8")
PUBLIC_PAGE = (ROOT / "config/public_page.php").read_text(encoding="utf-8")
DELIVERY = (ROOT / "config/public_entity_delivery.php").read_text(encoding="utf-8")
BLOG_ADMIN = (ROOT / "discadmin/blog.js").read_text(encoding="utf-8")


class Concept05PreviewAlignmentTests(unittest.TestCase):
    def test_preview_reuses_canonical_public_renderer_without_parallel_markup(self) -> None:
        self.assertIn("require_once __DIR__ . '/config/public_entity_delivery.php';", PREVIEW)
        self.assertIn("brvtalPublicPreviewPage($pdo, $snapshot)", PREVIEW)
        self.assertIn("brvtalPublicEntityDocument(", PREVIEW)
        self.assertIn("brvtal_public_entity_page($page, $seo, $analytics)", DELIVERY)
        self.assertNotIn("srcdoc=", PREVIEW.lower())
        self.assertNotIn("entity-hero", PREVIEW)
        self.assertNotIn("entity-card", PREVIEW)
        self.assertIn("Canonical public preview", PREVIEW)

    def test_preview_covers_1440_and_390_with_canonical_draft_states(self) -> None:
        self.assertIn('data-width="1440" data-height="900"', PREVIEW)
        self.assertIn('data-width="390" data-height="844"', PREVIEW)
        self.assertIn("const scale=Math.min(1,available/target.width)", PREVIEW)

        for field in (
            "'cover_image'",
            "'accent'",
            "'ticket_types'",
            "'lineup'",
            "'artwork'",
            "'artists'",
            "'relations'",
        ):
            self.assertIn(field, CONFIG)

        self.assertIn("brvtal_public_media_variant((string)$seo['image'], 'hero')", PUBLIC_PAGE)
        self.assertIn("brvtal_public_media_variant((string)$item['image'], 'card')", PUBLIC_PAGE)
        self.assertIn("brvtal_public_ticket_type_is_available($ticket)", CONFIG)
        self.assertIn("brvtal_blog_sanitize_html", CONFIG)

    def test_preview_auth_isolation_and_failure_states_remain_fail_closed(self) -> None:
        self.assertIn("brvtal_admin_require();", API)
        self.assertIn("brvtal_admin_require_csrf();", API)
        self.assertIn("PREVIEW_INTERNAL_ERROR", API)
        self.assertIn("http_response_code(500)", API)
        self.assertIn("Cache-Control: no-store", API)
        self.assertIn("Cross-Origin-Resource-Policy: same-origin", API)
        self.assertIn("BRVTAL_PUBLIC_PREVIEW_TTL = 600", CONFIG)
        self.assertIn("$_SESSION['public_previews']", CONFIG)
        self.assertRegex(CONFIG, r"\^\[a-f0-9\]\{48\}\$\/D")
        self.assertIn("unset($_SESSION['public_previews'][$token])", CONFIG)
        self.assertIn("if (!brvtal_admin_is_authenticated())", PREVIEW)
        self.assertIn("brvtal_preview_error(401", PREVIEW)
        self.assertIn("brvtal_preview_error(410", PREVIEW)
        self.assertIn("frame-ancestors 'self'", PREVIEW)
        self.assertIn('sandbox="allow-scripts allow-same-origin"', PREVIEW)
        self.assertNotIn("/api/public.php", API + CONFIG + PREVIEW + ADMIN)

    def test_supported_entities_share_accessible_responsive_preview_contract(self) -> None:
        expected = "['events', 'artists', 'sets', 'releases', 'blog', 'pages']"
        self.assertIn(expected, CONFIG)
        self.assertIn("PREVIEW_TYPE_NOT_ALLOWED", CONFIG)
        self.assertIn('aria-label="Preview viewport"', PREVIEW)
        self.assertIn('title="Canonical public preview"', PREVIEW)
        self.assertIn("min-height:44px", PREVIEW)
        self.assertIn("overflow:auto", PREVIEW)
        self.assertIn("prefers-reduced-motion:reduce", PREVIEW)
        self.assertIn("safe-area-inset-left", PREVIEW)
        self.assertIn("safe-area-inset-right", PREVIEW)
        self.assertIn("brvtal_blog_sanitize_html", CONFIG)
        self.assertIn("window.BRVTALPublicPreview?.bindButton(", BLOG_ADMIN)
        self.assertIn("'blog'", BLOG_ADMIN)
        self.assertIn("brvtal_public_memories_for_entity", PUBLIC_PAGE)
        self.assertIn("$data['related']['MEMORIES']", PUBLIC_PAGE)
        self.assertNotIn("'memories' => [", CONFIG)

        route_types = re.findall(r"'([a-z]+)'\s*=>\s*\[", CONFIG.split("$allowed = [", 1)[1].split("][$type]", 1)[0])
        self.assertEqual(["events", "artists", "sets", "releases", "blog", "pages"], route_types)

    def test_preview_type_authority_remains_server_canonical(self) -> None:
        canonical = "['events', 'artists', 'sets', 'releases', 'blog', 'pages']"
        duplicated_client = "['events','artists','sets','releases','blog','pages']"
        self.assertIn(canonical, CONFIG)
        self.assertIn("brvtalPublicPreviewTypes()", CONFIG)
        self.assertIn("PREVIEW_TYPE_NOT_ALLOWED", CONFIG)
        self.assertNotIn(duplicated_client, ADMIN)
        self.assertNotIn("canonicalTypes", ADMIN)
        self.assertNotIn("PREVIEW_TYPE_NOT_ALLOWED", ADMIN)
        self.assertIn("previewableLegacyTypes", ADMIN)
        self.assertIn("['events','artists','sets','pages']", ADMIN)


if __name__ == "__main__":
    unittest.main()
