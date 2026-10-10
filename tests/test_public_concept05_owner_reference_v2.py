#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FIXTURE = ROOT / "tests/fixtures/public-concept05-reference-v1.json"
HOME = ROOT / "config/public_home.php"
HOME_CSS = ROOT / "css/public-concept05-home.css"
HERO_CSS = ROOT / "css/public-concept05-hero.css"
HERO_E2E = ROOT / "tests/e2e/public-concept05-hero.spec.mjs"
MATRIX_E2E = ROOT / "tests/e2e/public-concept05-fidelity-matrix.spec.mjs"
EXPERIENCE = ROOT / "config/public_home.php"
NIGHTS_RUNTIME = ROOT / "js/public-concept05-nights-artists.js"
SOUND_RUNTIME = ROOT / "js/public-concept05-sound-memories.js"
CONNECTED_RUNTIME = ROOT / "js/public-concept05-connected.js"
SHELL = ROOT / "js/public-concept05-shell.js"


class PublicConcept05OwnerReferenceV2Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.fixture = json.loads(FIXTURE.read_text(encoding="utf-8"))
        cls.contract = cls.fixture["owner_reference_v2"]
        cls.home = HOME.read_text(encoding="utf-8")
        cls.home_css = HOME_CSS.read_text(encoding="utf-8")
        cls.hero_css = HERO_CSS.read_text(encoding="utf-8")
        cls.hero_e2e = HERO_E2E.read_text(encoding="utf-8")
        cls.matrix_e2e = MATRIX_E2E.read_text(encoding="utf-8")

    def test_hero_contract_covers_desktop_and_mobile_reference(self) -> None:
        self.assertEqual(2, self.fixture["version"])
        source = self.fixture["source"]
        self.assertEqual("docs/reference/home-concept05-owner-reference-v2.png", source["name"])
        self.assertEqual("f3d8434a605cee56d84d97307c0e3f5a6409b84d", source["git_blob_sha"])
        self.assertEqual("62041aa6", source["sha256_prefix"])
        self.assertEqual({"width": 1440, "height": 900}, self.contract["viewports"]["desktop"])
        self.assertEqual({"width": 390, "height": 844}, self.contract["viewports"]["mobile"])
        hero = self.contract["hero"]
        self.assertEqual("BR/VT/AL", hero["title_stack"])
        self.assertIn("--c5-hero-title-stack:2.08ch", self.hero_css)

    def test_hero_static_copy_respects_route_locale_and_keeps_cms_hook(self) -> None:
        # Exercise the actual PHP renderer rather than source comments or test titles.
        script = r"""
            require 'config/public_home.php';
            $source = file_get_contents('index.html');
            $results = [];
            foreach (['es', 'en', 'invalid'] as $locale) {
                $_GET['locale'] = $locale;
                $html = brvtal_public_home_identity($source);
                $results[$locale] = [
                    'es_manifesto' => str_contains($html, 'data-c5-hero-manifesto>MÁS QUE FIESTAS.<br>UNA CULTURA EN MOVIMIENTO.</strong>'),
                    'en_manifesto' => str_contains($html, 'data-c5-hero-manifesto>MORE THAN PARTIES.<br>A CULTURE IN MOTION.</strong>'),
                    'es_cta' => str_contains($html, 'EXPLORA BRVTAL <span>↘</span></a>'),
                    'en_cta' => str_contains($html, 'EXPLORE BRVTAL <span>↘</span></a>'),
                    'cms_hook' => str_contains($html, 'data-c5-hero-description'),
                ];
            }
            echo json_encode($results, JSON_THROW_ON_ERROR);
        """
        result = subprocess.run(
            ["php", "-r", script], cwd=ROOT, text=True,
            capture_output=True, check=True,
        )
        observed = json.loads(result.stdout)
        self.assertEqual(
            {
                "es": {"es_manifesto": True, "en_manifesto": False, "es_cta": True, "en_cta": False, "cms_hook": True},
                "en": {"es_manifesto": False, "en_manifesto": True, "es_cta": False, "en_cta": True, "cms_hook": True},
                "invalid": {"es_manifesto": True, "en_manifesto": False, "es_cta": True, "en_cta": False, "cms_hook": True},
            },
            observed,
        )

    def test_blocks_01_07_follow_reference_order_and_composition(self) -> None:
        self.assertEqual(
            ["hero","next-experience","nights","artists","sound","memories","journal","connected","footer"],
            self.contract["section_order"],
        )
        self.assertEqual(
            [["next-experience"],["nights","artists"],["sound","memories"],["journal","connected"]],
            self.contract["desktop_rows"],
        )
        self.assertEqual("owner-approved-v2", self.fixture["source"]["verification"])

    def test_next_experience_uses_published_cms_event(self) -> None:
        for marker in (
            "brvtal_public_next_experience(PDO $pdo",
            "brvtal_public_select_next_experience",
            "brvtal_public_next_experience_lineup",
            "brvtalPublicNextExperienceTicketTypes",
            "brvtal_public_render_next_experience",
        ):
            self.assertIn(marker, self.home)
        self.assertNotIn("GENESIS</h2>", self.home.split("function brvtal_public_render_next_experience",1)[1])

    def test_nights_and_artists_share_reference_row_with_cms_data(self) -> None:
        runtime = NIGHTS_RUNTIME.read_text(encoding="utf-8")
        self.assertIn("brvtal:roster-rendered", runtime)
        self.assertIn("#events", self.home_css)
        self.assertIn("#artists", self.home_css)
        self.assertIn("--c5-v2-row", self.home_css)

    def test_sound_and_memories_follow_reference_with_cms_data(self) -> None:
        runtime = SOUND_RUNTIME.read_text(encoding="utf-8")
        self.assertIn("brvtal:sets-library-rendered", runtime)
        self.assertIn("brvtal:memories-rendered", runtime)
        self.assertIn("#sets", self.home_css)
        self.assertIn("#media", self.home_css)
        self.assertIn("managed-media-fail-closed", self.contract["invariants"])

    def test_journal_and_connected_have_single_reference_composition(self) -> None:
        connected = CONNECTED_RUNTIME.read_text(encoding="utf-8")
        self.assertIn("window.BRVTALPublicDataPromise", connected)
        self.assertIn("if (str_contains($html, 'id=\"connected\"'))", self.home)
        self.assertIn("brvtalPublicHomeConcept05RemoveLegacyManifesto", self.home)
        self.assertIn("single-connected-section", self.contract["invariants"])
        self.assertIn("#transmissions", self.home_css)
        self.assertIn("#connected", self.home_css)

    def test_shell_navigation_mobile_bar_and_images_are_safe(self) -> None:
        shell = SHELL.read_text(encoding="utf-8")
        for label in self.contract["shell_navigation"]:
            self.assertIn(label, self.home)
        self.assertIn("c5-bottom-nav", self.home)
        self.assertIn("safeMedia", NIGHTS_RUNTIME.read_text(encoding="utf-8"))
        self.assertIn("fail", SOUND_RUNTIME.read_text(encoding="utf-8").lower())
        self.assertIn("document.documentElement.scrollWidth <= document.documentElement.clientWidth", self.hero_e2e)
        self.assertIn("document.documentElement.scrollWidth <= document.documentElement.clientWidth", self.matrix_e2e)
        self.assertIn("IntersectionObserver", shell)


if __name__ == "__main__":
    unittest.main()
