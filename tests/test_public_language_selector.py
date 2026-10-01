from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class PublicLanguageSelectorTests(unittest.TestCase):
    def test_selector_replaces_sound_toggle_and_updates_document_lang_without_reload(self) -> None:
        index = (ROOT / "index.html").read_text(encoding="utf-8")
        runtime = (ROOT / "js" / "app.js").read_text(encoding="utf-8")
        styles = (ROOT / "css" / "style.css").read_text(encoding="utf-8")

        self.assertIn('<html lang="es">', index)
        self.assertIn('<fieldset class="language-selector" id="languageSelector">', index)
        self.assertIn('<legend>Idioma / Language</legend>', index)
        self.assertNotIn('role="group"', index)
        self.assertIn('data-locale="es"', index)
        self.assertIn('data-locale="en"', index)
        self.assertIn('aria-pressed="true"', index)
        self.assertNotIn('id="soundToggle"', index)
        self.assertNotIn("AudioContext", runtime)
        self.assertNotIn("webkitAudioContext", runtime)
        self.assertNotIn("location.reload", runtime)
        self.assertIn("document.documentElement.lang = locale;", runtime)
        self.assertIn("window.BRVTALPublicLocale?.configure(window.BRVTALI18N);", runtime)
        self.assertIn("min-height:44px", styles)

        syntax = subprocess.run(
            ["node", "--check", "js/app.js"],
            cwd=ROOT,
            capture_output=True,
            text=True,
        )
        self.assertEqual(syntax.returncode, 0, syntax.stderr)

    def test_success_payload_without_i18n_policy_preserves_safe_first_paint(self) -> None:
        runtime = (ROOT / "js" / "app.js").read_text(encoding="utf-8")
        index = (ROOT / "index.html").read_text(encoding="utf-8")
        self.assertIn(
            "settings.i18n && typeof settings.i18n === 'object'",
            runtime,
        )
        self.assertIn("if (hasValidLocalePolicy) {", runtime)
        self.assertIn(
            "window.BRVTALPublicLocale?.configure(window.BRVTALI18N);",
            runtime,
        )
        self.assertIn('data-locale="es" aria-pressed="true"', index)
        self.assertIn(
            'data-locale="en" aria-pressed="false" data-cursor="EN" disabled',
            index,
        )

    def test_invalid_policy_fails_closed_without_publishing_empty_browser_policy(self) -> None:
        runtime = (ROOT / "js" / "app.js").read_text(encoding="utf-8")
        self.assertIn("i18nCandidate.available_locales.length > 0", runtime)
        self.assertIn("i18nCandidate.available_locales.every", runtime)
        self.assertIn("renderedLocales = new Set(", runtime)
        self.assertIn("availableLocales.every(locale => renderedLocales.has(locale))", runtime)
        self.assertIn("availableLocales.includes(defaultLocale)", runtime)
        self.assertIn("availableLocales.includes(canonicalLocale)", runtime)
        self.assertIn("renderedLocales.has(defaultLocale)", runtime)
        self.assertIn("renderedLocales.has(canonicalLocale)", runtime)
        self.assertIn("if (hasValidLocalePolicy) {", runtime)
        self.assertNotIn("if (i18n) {", runtime)

    def test_missing_policy_fails_closed_to_spanish_only(self) -> None:
        index = (ROOT / "index.html").read_text(encoding="utf-8")
        runtime = (ROOT / "js" / "app.js").read_text(encoding="utf-8")

        self.assertIn('<html lang="es">', index)
        self.assertIn(
            'data-locale="en" aria-pressed="false" data-cursor="EN" disabled',
            index,
        )
        self.assertIn(
            "button.disabled = !availableLocales.includes(button.dataset.locale);",
            runtime,
        )
        self.assertIn(
            "if (!selector || !config || !Array.isArray(config.availableLocales)) return;",
            runtime,
        )

    def test_first_visit_is_spanish_and_explicit_choice_persists(self) -> None:
        index = (ROOT / "index.html").read_text(encoding="utf-8")
        runtime = (ROOT / "js" / "app.js").read_text(encoding="utf-8")

        self.assertIn('<html lang="es">', index)
        self.assertIn("EVENTOS", index)
        self.assertIn("ARTISTAS", index)
        self.assertIn("CONTACTO", index)
        self.assertIn("const LOCALE_STORAGE_KEY = 'brvtal.public.locale';", runtime)
        self.assertIn("window.localStorage.getItem(LOCALE_STORAGE_KEY)", runtime)
        self.assertIn("window.localStorage.setItem(LOCALE_STORAGE_KEY, locale)", runtime)
        self.assertIn("let locale = config.defaultLocale;", runtime)
        self.assertIn("if (availableLocales.includes(storedLocale)) {", runtime)
        self.assertIn("locale = storedLocale;", runtime)
        self.assertIn("if (availableLocales.includes(routeLocale)) {", runtime)
        self.assertIn("locale = routeLocale;", runtime)
        self.assertIn("setLocale(button.dataset.locale, {persist:true});", runtime)
        self.assertNotIn("const supportedLocales", runtime)

        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        self.assertEqual(
            package["scripts"]["test:language-selector"],
            "python3 -m unittest tests/test_public_language_selector.py",
        )
        self.assertIn("npm run test:language-selector", package["scripts"]["test:integration"])


if __name__ == "__main__":
    unittest.main()
