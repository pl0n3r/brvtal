"""Regression contracts for the Cromo clarity issue #1656.

The browser fixture exercises interactions in tests/e2e/public-cromo-home.spec.mjs.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
JS = (ROOT / "js/public-cromo-home.js").read_text(encoding="utf-8")
CSS = (ROOT / "css/public-cromo-home.css").read_text(encoding="utf-8")
BROWSER = (ROOT / "tests/e2e/public-cromo-home.spec.mjs").read_text(encoding="utf-8")


class CromoVisualReviewTests(unittest.TestCase):
    def test_empty_sections_have_no_spacer(self):
        self.assertRegex(CSS, r"\.cr-sound\{min-height:0;")
        self.assertRegex(CSS, r"\.cr-end\{padding:clamp")
        self.assertIn("#cromo-home [hidden]{display:none !important}", CSS)

    def test_connected_grouped_entities(self):
        for heading in ("Noches", "Artistas", "Sets", "Releases", "Memorias"):
            self.assertIn("title: '" + heading + "'", JS)
        self.assertIn("items.slice(0, 2)", JS)
        self.assertIn("groups.append(renderGroups(entity))", JS)
        self.assertNotIn("if (first) choose(first.key)", JS)
        self.assertIn("Conectado resume 25 vínculos", BROWSER)

    def test_contact_form_accessible_layout(self):
        self.assertIn("class: 'form-challenge'", JS)
        self.assertIn("'aria-describedby': 'cromo-err-captcha'", JS)
        self.assertIn("submit.disabled = !token.value", JS)
        self.assertIn("retry.addEventListener('click', loadChallenge)", JS)
        self.assertIn("Contacto recupera el captcha fallido", BROWSER)

    def test_mobile_desktop_visibility(self):
        self.assertIn("@media(max-width:700px)", CSS)
        self.assertIn("#cromo-home .cr-cn2-groups", CSS)
        for width in ("390", "768", "900", "1440"):
            self.assertIn(width, BROWSER)


if __name__ == "__main__":
    unittest.main()
