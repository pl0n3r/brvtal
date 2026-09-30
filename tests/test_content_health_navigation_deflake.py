import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SPEC = ROOT / "tests" / "e2e" / "discadmin-content-health-navigation.spec.mjs"
RUNTIME = ROOT / "discadmin" / "content-health.js"


class ContentHealthNavigationDeflakeTests(unittest.TestCase):
    def _source(self) -> str:
        return SPEC.read_text(encoding="utf-8")

    def test_playwright_owns_content_health_click(self):
        source = self._source()
        self.assertIn(
            "await openButton.evaluate(button => button.setAttribute('data-health-open', ''));",
            source,
        )
        self.assertIn(
            "await expect(openButton).toHaveAttribute('data-health-open', '');",
            source,
        )
        self.assertIn("await openButton.click();", source)
        self.assertIn(
            "toEqual({section:'events',recordId:7,feedback:''});",
            source,
        )


    def test_initial_fallback_does_not_remount_existing_panel(self):
        runtime = RUNTIME.read_text(encoding="utf-8")
        self.assertIn(
            "if (!document.getElementById('brvtal-content-health')) mount();",
            runtime,
        )
        source = self._source()
        self.assertIn("window.__healthFallback=()=>callback(...args);", source)
        self.assertIn("await page.evaluate(() => window.__healthFallback());", source)
        self.assertGreaterEqual(
            source.count("await expect(openButton).toHaveAttribute('data-health-open', '');"),
            2,
        )

    def test_explicit_refresh_remains_available(self):
        runtime = RUNTIME.read_text(encoding="utf-8")
        self.assertIn(
            "window.BRVTALContentHealth = {mount,refresh:mount};",
            runtime,
        )
        source = self._source()
        self.assertIn(
            "await page.evaluate(() => window.BRVTALContentHealth.refresh());",
            source,
        )
        self.assertIn("await expect.poll(() => healthRequests).toBe(2);", source)

    def test_release_identity_is_synchronized(self):
        import re
        config = (ROOT / "config" / "version.php").read_text(encoding="utf-8")
        match = re.search(
            r"BRVTAL_APP_VERSION\s*=\s*'([^']+)'",
            config,
        )
        self.assertIsNotNone(match)
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        self.assertEqual(package["version"], match.group(1))

    def test_required_ci_command_executes_this_suite(self):
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        scripts = package["scripts"]
        self.assertEqual(
            scripts["test:content-health-deflake"],
            "python3 -m unittest tests/test_content_health_navigation_deflake.py",
        )
        self.assertIn(
            "npm run test:content-health-deflake",
            scripts["test:integration"],
        )

    def test_no_programmatic_click_or_timeout_inflation(self):
        source = self._source()
        self.assertNotRegex(source, r"\bbutton\.click\s*\(")
        self.assertNotRegex(source, r"expect\.poll\([^\n]+,\s*\{\s*timeout\s*:")
        self.assertNotRegex(source, r"test\.setTimeout\s*\(")
        self.assertNotRegex(source, r"waitForTimeout\s*\(")
        self.assertNotRegex(source, r"\bretries\s*:")


if __name__ == "__main__":
    unittest.main()
