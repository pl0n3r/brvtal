#!/usr/bin/env python3
"""BRVTAL #975 CMS-synthetic Concept 05 acceptance contracts."""
from __future__ import annotations
import hashlib
import json
import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CATEGORIES = ("hero-face", "crowd", "artists", "nights", "memories")

class HomeC05MediaPlaceholdersTests(unittest.TestCase):
    def test_five_placeholder_categories_use_authenticated_cms_media_registration(self):
        hashes = []
        for name in CATEGORIES:
            path = ROOT / "assets" / "placeholders" / "concept05" / f"{name}.webp"
            data = path.read_bytes()
            self.assertGreater(len(data), 200)
            self.assertEqual(b"RIFF", data[:4])
            self.assertEqual(b"WEBP", data[8:12])
            hashes.append(hashlib.sha256(data).hexdigest())
        self.assertEqual(5, len(set(hashes)))
        seed = (ROOT / "scripts/seed_concept05_media_placeholders.php").read_text()
        for token in ("--apply", "BRVTAL_C05_FIXTURE_ORIGIN",
                      "BRVTAL_C05_FIXTURE_COOKIE", "BRVTAL_C05_FIXTURE_CSRF",
                      "127\\.0\\.0\\.1", "CURLFile", "?action=upload",
                      "?action=update&id=", "'status' => 'published'",
                      "DUPLICATE_SOURCE_HASH"):
            self.assertIn(token, seed)
        if shutil.which("php"):
            run = subprocess.run(["php", "scripts/seed_concept05_media_placeholders.php"],
                                 cwd=ROOT, capture_output=True, text=True, check=False)
            self.assertEqual(0, run.returncode, run.stderr)
            payload = json.loads(run.stdout)
            self.assertEqual("dry-run", payload["mode"])
            self.assertTrue(payload["synthetic_only"])
            self.assertEqual(5, len(payload["sources"]))
            prohibited = subprocess.run(["php", "scripts/seed_concept05_media_placeholders.php",
                                          "--apply"], cwd=ROOT, capture_output=True, text=True,
                                         check=False)
            self.assertNotEqual(0, prohibited.returncode)
            self.assertIn("ISOLATED_AUTHENTICATED_FIXTURE_REQUIRED", prohibited.stderr)

    def test_admin_media_replacement_roundtrip_updates_published_home(self):
        settings = (ROOT / "discadmin/settings-v2.js").read_text()
        hero = (ROOT / "js/public-concept05-hero.js").read_text()
        home = (ROOT / "index.html").read_text()
        for key in ("hero_face", "crowd", "artists", "nights", "memories"):
            self.assertIn(f"sv2_c05_media_{key}", settings)
            self.assertIn(f'data-c5-editorial-media="{key}"', home)
            self.assertIn("'c05_media_' + key", settings)
            self.assertIn("'c05_media_' + image.dataset.c5EditorialMedia", hero)
        for token in ("item.status === 'published'", "persistJson('site'", "...assignedMedia",
                      "input.dataset.mediaId", "data-settings-media-picker"):
            self.assertIn(token, settings)
        self.assertIn("projectEditorialMedia(data)", hero)
        self.assertIn("image.onerror", hero)
        self.assertIn("image.removeAttribute('src')", hero)

    def test_browser_no_broken_images_390_1440_and_reference_evidence(self):
        spec = (ROOT / "tests/e2e/public-concept05-media-placeholders.spec.mjs").read_text()
        for viewport in ("390", "1440"):
            self.assertIn(viewport, spec)
        for token in ("screenshot", "broken", "reference", "published"):
            self.assertIn(token, spec.lower())
        self.assertTrue((ROOT / "docs/reference/home-concept05-owner-reference-v2.png").is_file())

if __name__ == "__main__":
    unittest.main()
