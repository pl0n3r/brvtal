#!/usr/bin/env python3
"""Executable contracts for BRVTAL #972 Sound + Memories.

The owner-reference A/B screenshots remain a separate mandatory PR review gate;
static source checks or this bridge must never be called visual approval.
"""
from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
E2E_PATH = "tests/e2e/public-concept05-sound-memories.spec.mjs"


class HomeC05SoundMemoriesTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.sets = (ROOT / "js/public-sets-library.js").read_text(encoding="utf-8")
        cls.media = (ROOT / "js/public-media.js").read_text(encoding="utf-8")
        cls.enhancer = (ROOT / "js/public-concept05-sound-memories.js").read_text(encoding="utf-8")
        cls.spec = (ROOT / E2E_PATH).read_text(encoding="utf-8")
        cls.css = (ROOT / "css/public-concept05-sound-memories.css").read_text(encoding="utf-8")

    def test_sound_uses_published_cms_set_and_numbered_list(self) -> None:
        # The canonical renderer owns published records, numbering and safe destinations.
        for required in (
            "const visible = visibleSets();",
            "String(index + 1).padStart(3, '0')",
            "const external = safeHttpUrl(item?.external_url ?? '');",
            "const canonical = routeUrl('sets', slug) || '/#sets';",
            "window.BRVTALPublicSetsLibrary",
        ):
            with self.subTest(required=required):
                self.assertIn(required, self.sets)
        self.assertIn("window.BRVTALPublicSetsLibrary.render(setData)", self.spec)
        self.assertIn("c5-sound-feature", self.enhancer)
        self.assertIn("EXPLORAR SONIDO", self.enhancer)
        self.assertIn("NO PUBLISHED SETS YET.", self.sets)
        self.assertIn("function safeEmbedUrl(value)", self.enhancer)
        self.assertIn("window.BRVTALPublicDataPromise", self.enhancer)
        self.assertIn("iframe.referrerPolicy = 'no-referrer'", self.enhancer)
        self.assertIn("allow-scripts allow-same-origin allow-presentation", self.enhancer)
        self.assertIn("Concept 05 loads only allowlisted official embed after user activation", self.spec)
        self.assertIn("Concept 05 rejects untrusted CMS embeds", self.spec)

    def test_memories_use_curated_cms_media_with_safe_links(self) -> None:
        self.assertIn("window.__memoriesArg?.some(item => item.id === 999)", self.spec)
        self.assertIn("window.BRVTALPublicMedia.render(memoryData)", self.spec)
        self.assertIn("function failMedia(host, media)", self.enhancer)
        self.assertIn("trigger.disabled = true", self.enhancer)
        self.assertIn("if (!curated.length && annotation)", self.enhancer)
        self.assertIn("EXPLORAR ARCHIVO", self.enhancer)
        self.assertIn("else if (!archive && route)", self.enhancer)
        self.assertIn("Sound and archive actions disappear when their CMS destination is unavailable", self.spec)
        self.assertIn("noopener noreferrer", self.sets)
        self.assertIn("function relationHref(relation)", self.media)
        self.assertNotIn("fetch(", self.enhancer)

    def test_desktop_mobile_reference_contract_and_capture_gate(self) -> None:
        reference = ROOT / "docs/reference/home-concept05-owner-reference-v2.png"
        self.assertTrue(reference.is_file(), "Owner-v2 reference missing; A/B review cannot pass")
        with reference.open("rb") as source:
            self.assertEqual(source.read(8), bytes.fromhex("89504e470d0a1a0a"))
        self.assertIn("width: 1440", self.spec)
        self.assertIn("width: 390", self.spec)
        self.assertIn("grid-template-columns:repeat(12,minmax(0,1fr));", self.css)
        self.assertIn("grid-template-columns:repeat(2,minmax(0,1fr));", self.css)
        self.assertIn("Concept 05 mobile Sound and Memories remain touch-safe", self.spec)
        # Screenshot comparison must additionally be attached to and reviewed on the PR.

    def test_browser_scenarios_cover_sound_memories(self) -> None:
        binary = ROOT / "node_modules/.bin/playwright"
        self.assertTrue(binary.is_file() and shutil.which("node"), "Playwright/browser unavailable; cannot certify E2E")
        run = subprocess.run(
            [str(binary), "test", E2E_PATH, "--project=chromium", "--workers=1"],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=240,
            check=False,
        )
        self.assertEqual(run.returncode, 0, (run.stdout + "\n" + run.stderr)[-12000:])


if __name__ == "__main__":
    unittest.main()
