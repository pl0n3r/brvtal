import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "tests" / "e2e" / "discadmin-form-dialogs.spec.mjs"
TEST_MARKER = "test('Tab stays inside the Content Core dialog'"


def content_core_focus_test() -> str:
    source = SOURCE.read_text(encoding="utf-8")
    marker = source.find(TEST_MARKER)
    if marker < 0:
        raise AssertionError("Content Core focus test is missing")
    return source[marker:]


class ContentCoreDialogFocusDeflakeTests(unittest.TestCase):
    def test_waits_for_initial_focus_before_overriding_focus(self):
        block = content_core_focus_test()
        initial = "await expect(page.locator('#e_title')).toBeFocused();"
        override = "await save.focus();"
        confirm = "await expect(save).toBeFocused();"
        self.assertIn(initial, block)
        self.assertIn(override, block)
        self.assertIn(confirm, block)
        self.assertLess(block.index(initial), block.index(override))
        self.assertLess(block.index(override), block.index(confirm))

    def test_keeps_bidirectional_focus_trap_without_timeout_inflation(self):
        block = content_core_focus_test()
        forward = [
            "await save.focus();",
            "await page.keyboard.press('Tab');",
            "await expect(close).toBeFocused();",
        ]
        backward = [
            "await page.keyboard.press('Shift+Tab');",
            "await expect(save).toBeFocused();",
        ]
        cursor = -1
        for fragment in [*forward, *backward]:
            position = block.find(fragment, cursor + 1)
            self.assertGreater(position, cursor, fragment)
            cursor = position
        for forbidden in ("waitForTimeout(", "setTimeout(", ".retry(", "timeout:"):
            self.assertNotIn(forbidden, block)

    def test_programmatic_focus_sequence_remains_deterministic(self):
        block = content_core_focus_test()
        opener = "await page.getByRole('button', { name: 'CONTENT CORE EVENT' }).click();"
        initial = "await expect(page.locator('#e_title')).toBeFocused();"
        save = "await save.focus();"
        tab = "await page.keyboard.press('Tab');"
        self.assertEqual(block.count(initial), 1)
        self.assertEqual(block.count(save), 1)
        self.assertLess(block.index(opener), block.index(initial))
        self.assertLess(block.index(initial), block.index(save))
        self.assertLess(block.index(save), block.index(tab))


if __name__ == "__main__":
    unittest.main()
