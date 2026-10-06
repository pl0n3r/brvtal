#!/usr/bin/env python3
"""Concept 05 Theme Studio fidelity contracts."""

from __future__ import annotations

import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
STUDIO_JS = (ROOT / "discadmin/theme-studio-v2.js").read_text(encoding="utf-8")
STUDIO_CSS = (ROOT / "discadmin/theme-studio-v2.css").read_text(encoding="utf-8")
CONFIG_JS = (ROOT / "discadmin/theme-studio-configuration.js").read_text(encoding="utf-8")
RUNTIME_JS = (ROOT / "js/public-theme-runtime.js").read_text(encoding="utf-8")
E2E = (ROOT / "tests/e2e/discadmin-theme-studio-concept05.spec.mjs").read_text(encoding="utf-8")


class Concept05ThemeStudioFidelityTests(unittest.TestCase):
    def test_theme_studio_exposes_only_consumed_concept05_tokens(self) -> None:
        panel = STUDIO_JS.split("function panel(theme)", 1)[1].split("function currentTheme()", 1)[0]
        for required in (
            "BLACK · canvas",
            "PAPER · foreground",
            "RED · mother signal",
            "SIGNAL · neon accent",
            "fontSelectField('display'",
            "fontSelectField('body'",
            "fontSelectField('mono'",
            "toggleField('grain'",
            "toggleField('glitch'",
            "selectField('motion'",
            "selectField('menuStyle'",
            "selectField('logoPosition'",
        ):
            self.assertIn(required, panel)

        self.assertNotIn("textField('h1'", panel)
        self.assertNotIn("textField('tracking'", panel)
        self.assertNotIn("textField('bodySize'", panel)
        self.assertIn("applyColors(theme.colors)", RUNTIME_JS)
        self.assertIn("applyTypography(theme.typography)", RUNTIME_JS)
        self.assertIn("applyNavigation(theme.navigation, theme.sound)", RUNTIME_JS)
        self.assertIn("applyEffects(theme.effects)", RUNTIME_JS)
        self.assertIn("applyBranding(theme.branding)", RUNTIME_JS)
        self.assertIn("bodySize:CONCEPT05_VISUAL.typography.bodySize", STUDIO_JS)

    def test_theme_studio_previews_canonical_home_at_1440_and_390(self) -> None:
        self.assertIn("CANONICAL HOME PREVIEW", STUDIO_JS)
        self.assertNotIn("LOCAL THEME PREVIEW", STUDIO_JS)
        self.assertIn('data-theme-canonical-preview', STUDIO_JS)
        self.assertIn('src="/?theme_studio_preview=1"', STUDIO_JS)
        self.assertIn("frame.contentWindow.postMessage", STUDIO_JS)
        self.assertIn("concept05PreviewTheme(t)", STUDIO_JS)
        self.assertNotIn("data-preview-favicon", STUDIO_JS)
        self.assertIn("logo:String(b.logo || '')", STUDIO_JS)
        self.assertIn("mobileLogo:String(b.mobileLogo || '')", STUDIO_JS)
        self.assertNotIn("V2.previewMode === 'mobile' ? b.mobileLogo", STUDIO_JS)
        self.assertNotIn("aspect-ratio:390/844", STUDIO_CSS)
        self.assertIn("const width = mobile ? 390 : 1440;", STUDIO_JS)
        self.assertIn("const height = mobile ? 844 : 900;", STUDIO_JS)
        for legacy_renderer in (
            "tsv2-preview-hero",
            "tsv2-preview-grid",
            "tsv2-preview-nav",
            "tsv2-preview-browser",
        ):
            self.assertNotIn(legacy_renderer, STUDIO_JS)

        self.assertIn("theme_studio_preview", RUNTIME_JS)
        self.assertIn("window.addEventListener('message', receivePreviewTheme)", RUNTIME_JS)
        self.assertIn("event.origin !== window.location.origin", RUNTIME_JS)
        self.assertIn("event.source !== window.parent", RUNTIME_JS)
        self.assertIn("applyTheme(previewTheme)", RUNTIME_JS)
        self.assertIn("toHaveAttribute('width','1440')", E2E)
        self.assertIn("toHaveAttribute('height','900')", E2E)
        self.assertIn("toHaveAttribute('width','390')", E2E)
        self.assertIn("toHaveAttribute('height','844')", E2E)
        self.assertIn("data-theme-preview", E2E)

    def test_draft_activate_dirty_and_reset_share_the_canonical_contract(self) -> None:
        for required in (
            "persist(false)",
            "persist(true)",
            "SAVE DRAFT",
            "SAVE & ACTIVATE",
            "UNSAVED CHANGES",
            "RESET VISUALS TO CONCEPT 05",
            "preset('concept05')",
            "markDraftDirty()",
        ):
            self.assertIn(required, STUDIO_JS)
        self.assertIn("Local recovery only · Save Draft and Save & Activate remain explicit.", STUDIO_JS)
        self.assertIn("sceneIndicator:CONCEPT05_VISUAL.navigation.sceneIndicator", STUDIO_JS)
        self.assertIn("h1:CONCEPT05_VISUAL.typography.h1", STUDIO_JS)
        self.assertIn("bodySize:CONCEPT05_VISUAL.typography.bodySize", STUDIO_JS)
        self.assertIn("tracking:CONCEPT05_VISUAL.typography.tracking", STUDIO_JS)

    def test_theme_studio_rejects_arbitrary_layout_entropy_and_preserves_accessibility(self) -> None:
        panel = STUDIO_JS.split("function panel(theme)", 1)[1].split("function currentTheme()", 1)[0].lower()
        for forbidden in (
            "custom css",
            "custom js",
            "z-index",
            "breakpoint",
            "timeline",
            "position:absolute",
            "grid-template",
        ):
            self.assertNotIn(forbidden, panel)

        self.assertNotIn("[data-preview-brand]", CONFIG_JS)
        self.assertIn("CHOOSE FROM MEDIA", CONFIG_JS)
        self.assertIn("aria-modal=\"true\"", CONFIG_JS)
        self.assertIn("min-height:44px", STUDIO_CSS)
        self.assertIn("@media(prefers-reduced-motion:reduce)", STUDIO_CSS)
        self.assertIn("window.parent === window", RUNTIME_JS)
        self.assertIn("message.version !== 1", RUNTIME_JS)


if __name__ == "__main__":
    unittest.main()
