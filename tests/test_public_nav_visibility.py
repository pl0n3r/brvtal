"""Executable static acceptance for #1651's fail-open public navigation."""
import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
JS = (ROOT / "js/public-cromo-home.js").read_text()
CSS = (ROOT / "css/public-cromo-home.css").read_text()
BROWSER = (ROOT / "tests/e2e/public-cromo-home.spec.mjs").read_text()


class PublicNavVisibilityTests(unittest.TestCase):
    def test_mobile_menu_controls(self):
        for term in ('cromo-section-nav', 'cr-nav-open', "'Escape'",
                     'aria-expanded', 'cromo-menu-open', "event.key !== 'Tab'"):
            self.assertIn(term, JS if term != 'cr-nav-open' else CSS)
        self.assertIn('min-height:44px', CSS)
        self.assertIn('prefers-reduced-motion:reduce', CSS)

    def test_sound_links_are_canonical(self):
        self.assertIn("['discos', 'Sets']", JS)
        self.assertIn("['sonido', 'Sonido']", JS)
        self.assertIn("href: '#discos'", JS)
        self.assertIn('Cromo mobile menu is visible', BROWSER)

    def test_sections_hidden_when_no_published_content(self):
        for term in ('const sound = sets.length ? el(', 'const setsSec = sets.length ? buildSets(sets) : null',
                     'const artistsSec = artists.length ? buildArtists(artists) : null', 'const memoriesSec = memories.length ? buildMemories(memories) : null',
                     'const journalSec = posts.length ? buildJournal(posts) : null', 'const nights = hasNights ? buildNights('):
            self.assertIn(term, JS)
        self.assertIn('hides every empty editorial section', BROWSER)

    def test_api_failure_not_empty(self):
        self.assertIn(".catch(() => { /* keep the Concept 05 home */ });", JS)
        self.assertIn('failed public API does not treat data as an empty published catalog', BROWSER)
        self.assertIn("['contacto', 'Contacto']", JS)

    def test_publication_changes(self):
        self.assertIn('Cromo adjusts nav to published data on each successful CMS reload', BROWSER)
        self.assertIn('const navLinks = [', JS)
        self.assertIn('data.archive?.events', JS)


if __name__ == '__main__':
    unittest.main()
