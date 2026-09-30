import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class MainPromiseReliabilityTests(unittest.TestCase):
    def test_dashboard_persistence_is_explicit_fire_and_forget(self):
        source = (ROOT / "discadmin" / "dashboard-v2.js").read_text(encoding="utf-8")
        self.assertIn(
            "item.visible = true;\n        void persist();",
            source,
        )
        self.assertIn(
            "layout.modules = defaultLayout().modules.map(item => ({...item}));\n      void persist();",
            source,
        )

    def test_public_shell_hydration_is_explicit_fire_and_forget(self):
        source = (ROOT / "js" / "public-concept05-shell.js").read_text(encoding="utf-8")
        self.assertIn("void hydratePages();", source)
        self.assertNotIn("\n    hydratePages();", source)

    def test_public_hero_has_safe_async_start_boundary(self):
        source = (ROOT / "js" / "public-concept05-hero.js").read_text(encoding="utf-8")
        self.assertIn("function startInit()", source)
        self.assertIn("void init().catch(error => {", source)
        self.assertIn("void ready.then(startInit, startInit);", source)
        self.assertIn("window.addEventListener('load', startInit, {once:true});", source)
        self.assertNotIn("ready.finally(init)", source)

    def test_hero_slider_normalized_media_promises_are_explicitly_ignored(self):
        source = (ROOT / "discadmin" / "hero-slider.js").read_text(encoding="utf-8")
        self.assertEqual(source.count("void mediaPromise"), 2)
        self.assertIn(
            "void mediaPromise\n"
            "        .then(result => applyMediaLoad(result, revision))\n"
            "        .catch(error => {",
            source,
        )
        self.assertIn("Hero Slider media controls degraded.", source)
        self.assertIn("void mediaPromise.then(() => {});", source)

    def test_changed_javascript_remains_syntax_valid(self):
        for relative in (
            "discadmin/dashboard-v2.js",
            "js/public-concept05-shell.js",
            "js/public-concept05-hero.js",
            "discadmin/hero-slider.js",
        ):
            subprocess.run(
                ["node", "--check", str(ROOT / relative)],
                cwd=ROOT,
                check=True,
                text=True,
                capture_output=True,
                timeout=30,
            )

    def test_deploy_bound_version_is_0_1_91(self):
        version = (ROOT / "config" / "version.php").read_text(encoding="utf-8")
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        self.assertIn("BRVTAL_APP_VERSION = '0.1.91'", version)
        self.assertEqual(package["version"], "0.1.91")


if __name__ == "__main__":
    unittest.main()
