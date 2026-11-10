"""Regresiones del Grid de Conectado: no reservar tracks para botones ocultos."""

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class CromoCollapsedGridGapTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.script = (ROOT / "js/public-cromo-home.js").read_text(encoding="utf-8")
        cls.css = (ROOT / "css/public-cromo-home.css").read_text(encoding="utf-8")
        cls.browser = (ROOT / "tests/e2e/public-cromo-home.spec.mjs").read_text(encoding="utf-8")

    def test_hidden_items_do_not_reserve_grid_tracks(self):
        self.assertIn("const listItem = el('li', {}, button);", self.script)
        self.assertIn("if (i >= LIMIT) listItem.classList.add('cr-is-extra');", self.script)
        self.assertIn("ul.append(listItem);", self.script)
        self.assertRegex(self.css, r"(?m)^#cromo-home \.cr-cn2-list > li\.cr-is-extra\s*\{\s*display\s*:\s*none\s*\}")
        self.assertIn("Math.abs(initialMetrics.listHeight - initialMetrics.expected)", self.browser)
        self.assertIn("initialMetrics.trailingGap", self.browser)
        self.assertIn("initialMetrics.hiddenDisplays", self.browser)

    def test_expanding_restores_only_real_items(self):
        self.assertIn("if (i >= LIMIT) button.classList.add('cr-is-extra');", self.script)
        self.assertIn("items.get(key).classList.contains('cr-is-extra')", self.script)
        self.assertRegex(
            self.css,
            r"(?m)^#cromo-home \.cr-cn2-col\.cr-is-open \.cr-cn2-list > li\.cr-is-extra\s*\{\s*display\s*:\s*block\s*\}",
        )
        self.assertIn("await expect(rows.filter({ visible: true })).toHaveCount(count);", self.browser)
        self.assertIn("await expect(rows.filter({ visible: true })).toHaveCount(6);", self.browser)
        self.assertIn("await expect(more).toBeFocused();", self.browser)

    def test_responsive_and_keyboard_contract(self):
        self.assertIn("for (const count of [0, 1, 6, 32])", self.browser)
        self.assertIn("[390, 768, 900, 1440]", self.browser)
        self.assertIn("await page.keyboard.press('Enter')", self.browser)
        self.assertIn("await page.keyboard.press('Escape')", self.browser)
        self.assertIn("await noOverflow(page)", self.browser)
        self.assertIn("initialMetrics.touchHeight", self.browser)
        self.assertIn("await expect(page.locator('#contacto form')).toBeAttached();", self.browser)
        self.assertIn("await expect(page.locator('#cromo-section-nav')).toBeAttached();", self.browser)


if __name__ == "__main__":
    unittest.main()
