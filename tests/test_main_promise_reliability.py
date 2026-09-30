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
        self.assertIn("void mediaPromise", source)
        self.assertIn(".then(result => applyMediaLoad(result, revision))", source)
        self.assertIn(".catch(error => {", source)
        self.assertIn("Hero Slider media controls degraded.", source)
        self.assertIn("void mediaPromise.then(() => {});", source)

    def test_legacy_s9383_async_boundaries_handle_rejections_visibly(self):
        expectations = {
            "discadmin/settings-v2.js": [
                "save(saveButton.dataset.settingsSave).catch(error => feedback('error'",
                "loadSecurity(true).catch(error => feedback('error'",
                "openPicker(picker).catch(error => feedback('error'",
            ],
            "discadmin/theme-studio-v2.js": [
                "persist(false).catch(error => window.BRVTALFeedback?.error(",
                "persist(true).catch(error => window.BRVTALFeedback?.error(",
                "loadThemeStudioV2(V2.editingSlug).catch(error => window.BRVTALFeedback?.error(",
            ],
            "discadmin/admin-modules.js": [
                "}).catch(() => placeholderFor(img));",
                "await hydrateContentCoreThumbs(host);",
                "Feedback.error('Unable to load ' + section",
                "await hydrateContentCoreThumbs(document);",
            ],
            "discadmin/media-library.js": [
                "uploadFiles(input.files).catch(error => notify('error'",
                "uploadFiles(e.dataTransfer?.files).catch(error => notify('error'",
            ],
            "discadmin/system-status-v2.js": [
                "load(root).catch(error => { root.innerHTML =",
                "load(current).catch(error => { current.innerHTML =",
                "SYSTEM STATUS UNAVAILABLE",
            ],
            "discadmin/system-status-storage.js": [
                "refresh(true).catch(() => applyUnavailable())",
                "refresh(false).catch(() => applyUnavailable())",
            ],
            "discadmin/backups.js": [
                "load(panel).catch(error => { panel.innerHTML =",
                "BACKUPS UNAVAILABLE",
            ],
            "discadmin/releases.js": ["refresh().catch(error => setStatus("],
            "discadmin/totp-login.js": [
                "verify().catch(error => fail(",
                "Verification failed.",
            ],
            "js/public-contact.js": [
                "loadChallenge(form).catch(() => setStatus(",
                "ANTI-BOT SERVICE UNAVAILABLE. TRY AGAIN LATER.",
            ],
            "js/hero-slider.js": [
                "void init().catch(error => {",
                "console.warn('[BRVTAL] Hero Slider initialization failed.'",
            ],
        }
        for relative, snippets in expectations.items():
            source = (ROOT / relative).read_text(encoding="utf-8")
            with self.subTest(relative=relative):
                for snippet in snippets:
                    self.assertIn(snippet, source)

        settings = (ROOT / "discadmin" / "settings-v2.js").read_text(encoding="utf-8")
        self.assertNotIn("loadSecurity(true).catch(() => {})", settings)

    def test_required_ci_command_executes_this_suite(self):
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        scripts = package["scripts"]
        self.assertEqual(
            scripts["test:reliability"],
            "python3 -m unittest tests/test_main_promise_reliability.py",
        )
        self.assertIn("npm run test:reliability", scripts["test:integration"])

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

    def test_deploy_bound_version_matches_package(self):
        version = (ROOT / "config" / "version.php").read_text(encoding="utf-8")
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        expected = f"BRVTAL_APP_VERSION = '{package['version']}'"
        self.assertIn(expected, version)
        self.assertRegex(package["version"], r"^\d+\.\d+\.\d+$")


if __name__ == "__main__":
    unittest.main()
