import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
JS = ROOT / "discadmin" / "seo-workspace.js"
SPEC = ROOT / "tests" / "e2e" / "discadmin-seo-workspace.spec.mjs"


def function_block(source: str, start_marker: str, end_marker: str) -> str:
    start = source.index(start_marker)
    end = source.index(end_marker, start)
    return source[start:end]


def playwright_test(source: str, title: str) -> str:
    marker = f"test('{title}'"
    start = source.index(marker)
    end = source.find("\ntest(", start + len(marker))
    return source[start:] if end < 0 else source[start:end]


class SeoEditorFocusRaceTests(unittest.TestCase):
    def test_initial_focus_is_synchronous_and_not_deferred(self):
        source = JS.read_text(encoding="utf-8")
        block = function_block(source, "  function openEditor(", "\n\n  function closeEditor(")
        show = "if (typeof overlay.showModal === 'function') overlay.showModal();"
        focus = "node('seo-editor-title-input')?.focus();"
        self.assertIn(show, block)
        self.assertIn(focus, block)
        self.assertNotIn("requestAnimationFrame", block)
        self.assertLess(block.index(show), block.index(focus))
        self.assertEqual(block.count(focus), 1)

    def test_description_interaction_is_not_overridden_by_later_frame(self):
        source = SPEC.read_text(encoding="utf-8")
        block = playwright_test(
            source,
            "editor initial focus is immediate and does not steal description focus on later frame",
        )
        sequence = [
            "await expect(title).toBeFocused();",
            "await description.focus();",
            "await description.fill('Immediate description edit.');",
            "requestAnimationFrame(() => resolve())",
            "await expect(description).toBeFocused();",
            "toHaveText('Immediate description edit.')",
        ]
        cursor = -1
        for fragment in sequence:
            position = block.find(fragment, cursor + 1)
            self.assertGreater(position, cursor, fragment)
            cursor = position
        self.assertNotIn("waitForTimeout(", block)
        self.assertNotIn(".retry(", block)

    def test_failed_save_contract_preserves_authored_description(self):
        source = SPEC.read_text(encoding="utf-8")
        block = playwright_test(
            source,
            "failed save remains visible and preserves authored input for retry",
        )
        for fragment in (
            "fill('Unsaved retry description.')",
            "toHaveText('Unsaved retry description.')",
            "form.requestSubmit()",
            "SEO save failed: SEO_WRITE_FAILED",
            "toBeVisible()",
            "toHaveValue('Unsaved retry description.')",
        ):
            self.assertIn(fragment, block)

    def test_playwright_spec_keeps_focus_and_retry_regressions(self):
        source = SPEC.read_text(encoding="utf-8")
        focus = playwright_test(
            source,
            "editor initial focus is immediate and does not steal description focus on later frame",
        )
        failed = playwright_test(
            source,
            "failed save remains visible and preserves authored input for retry",
        )
        self.assertIn("await expect(title).toBeFocused();", focus)
        self.assertIn("await expect(description).toBeFocused();", focus)
        self.assertIn("await expect(page.locator('#seo-workspace-editor')).toBeVisible();", failed)
        for block in (focus, failed):
            self.assertNotIn("waitForTimeout(", block)
            self.assertNotIn("timeout:", block)

    def test_release_identity_is_v01100(self):
        version = (ROOT / "config" / "version.php").read_text(encoding="utf-8")
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        self.assertIn("BRVTAL_APP_VERSION = '0.1.100'", version)
        self.assertEqual(package["version"], "0.1.100")


if __name__ == "__main__":
    unittest.main()
