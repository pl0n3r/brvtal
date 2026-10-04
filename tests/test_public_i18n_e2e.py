#!/usr/bin/env python3
"""Cross-surface ES/EN regression closeout for BRVTAL#857."""
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
    )


class PublicI18nE2ETests(unittest.TestCase):
    def test_language_switch_is_accessible_persistent_and_reload_free_across_public_surfaces(self) -> None:
        selector = read("tests/e2e/public-language-selector.spec.mjs")
        contact = read("tests/e2e/public-contact-page.spec.mjs")

        self.assertIn(
            "ES/EN switches without navigation and persists the explicit choice",
            selector,
        )
        self.assertIn(
            "language selector stays keyboard-accessible and layout-safe on desktop and mobile",
            selector,
        )
        self.assertIn("getByRole('group', { name:'Idioma / Language' })", selector)
        self.assertIn("await page.keyboard.press('Enter')", selector)
        self.assertIn("localStorage.getItem('brvtal.public.locale')", selector)
        self.assertIn("expect(navigations).toBe(0)", selector)

        self.assertIn(
            "Contact follows persisted locale without navigation across desktop and mobile",
            contact,
        )
        self.assertIn("localStorage.setItem('brvtal.public.locale', 'en')", contact)
        self.assertIn("new CustomEvent('brvtal:localechange'", contact)
        self.assertIn("new StorageEvent('storage'", contact)
        self.assertIn("await page.reload({ waitUntil:'domcontentloaded' })", contact)
        self.assertIn("width: 1440, height: 900", contact)
        self.assertIn("width: 390, height: 844", contact)
        self.assertIn("document.documentElement.scrollWidth <= window.innerWidth", contact)

        run_checked(["node", "--check", "tests/e2e/public-language-selector.spec.mjs"])
        run_checked(["node", "--check", "tests/e2e/public-contact-page.spec.mjs"])

    def test_localized_runtime_preserves_canonical_hreflang_indexing_and_layout_contracts(self) -> None:
        run_checked([
            "python3",
            "-m",
            "unittest",
            "tests.test_public_i18n_routing.PublicI18nRoutingTests."
            "test_localized_routes_emit_canonical_and_reciprocal_hreflang",
            "tests.test_public_i18n_indexing.PublicI18nIndexingTests."
            "test_localized_sitemap_contains_only_published_canonical_variants",
            "tests.test_public_i18n_indexing.PublicI18nIndexingTests."
            "test_drafts_private_content_and_invalid_locales_are_never_indexed",
        ])

        selector = read("tests/e2e/public-language-selector.spec.mjs")
        contact = read("tests/e2e/public-contact-page.spec.mjs")
        self.assertIn("width: 390, height: 844", selector)
        self.assertIn("document.documentElement.scrollWidth <= window.innerWidth", selector)
        self.assertIn("Contact is keyboard-usable on desktop", contact)
        self.assertIn("Contact stays readable and touch-safe on mobile", contact)
        self.assertIn("width: 1440, height: 900", contact)
        self.assertIn("width: 390, height: 844", contact)

        package = json.loads(read("package.json"))
        version_match = re.search(
            r"BRVTAL_APP_VERSION\s*=\s*'([^']+)'",
            read("config/version.php"),
        )
        self.assertIsNotNone(version_match)
        self.assertEqual(package["version"], version_match.group(1))
        self.assertEqual(
            package["scripts"]["test:i18n-e2e"],
            "python3 -m unittest tests/test_public_i18n_e2e.py",
        )
        self.assertIn("npm run test:i18n-e2e", package["scripts"]["test:integration"])


if __name__ == "__main__":
    unittest.main()
