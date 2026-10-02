#!/usr/bin/env python3
"""Phase 3 runtime locale regressions for BRVTAL#856."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class PublicI18nDynamicRuntimeTests(unittest.TestCase):
    def test_late_loaded_content_and_contact_messages_follow_active_locale_without_reload(self) -> None:
        loader = read("js/public-runtime-loader.js")
        contact = read("js/public-contact.js")
        page = read("config/public_contact_page.php")

        self.assertIn("new MutationObserver(", loader)
        self.assertIn("attributeFilter:['data-locale']", loader)
        self.assertIn("record.addedNodes?.forEach", loader)
        self.assertIn("applyLocalizedNode(node, activeLocale())", loader)
        self.assertIn("new CustomEvent(LOCALE_EVENT", loader)
        self.assertIn("brvtal:localechange", loader)
        self.assertIn("window.BRVTALPublicLocaleRuntime", loader)

        self.assertIn("window.addEventListener(LOCALE_EVENT", contact)
        self.assertIn("window.addEventListener('storage'", contact)
        self.assertIn("brvtal.public.locale", contact)
        self.assertIn("applyLocale(root, event?.detail?.locale)", contact)
        self.assertIn("status.dataset.contactI18nKey = key", contact)
        self.assertIn("status.dataset.contactI18nReplacements", contact)
        self.assertIn("element.dataset.text = value", contact)
        self.assertIn("CONTACT_CATALOG", contact)
        self.assertIn("'status.sent':'MENSAJE ENVIADO / SEÑAL RECIBIDA'", contact)
        self.assertIn("'status.sent':'MESSAGE SENT / SIGNAL RECEIVED'", contact)
        self.assertNotIn("location.reload(", loader + contact)

        self.assertIn('<html lang="es">', page)
        self.assertIn('data-contact-i18n-key="contact.title">CONTACTO</h1>', page)
        self.assertIn(
            'data-contact-i18n-key="contact.submit">ENVIAR SEÑAL ↗</button>',
            page,
        )

        for command in (
            ["node", "--check", "js/public-runtime-loader.js"],
            ["node", "--check", "js/public-contact.js"],
            ["php", "-l", "config/public_contact_page.php"],
        ):
            subprocess.run(
                command,
                cwd=ROOT,
                check=True,
                capture_output=True,
                text=True,
            )

    def test_private_untrusted_or_invalid_translation_never_reaches_public_runtime(self) -> None:
        loader = read("js/public-runtime-loader.js")
        contact = read("js/public-contact.js")

        for source in (loader, contact):
            self.assertIn("text.includes('<') || text.includes('>')", source)
            self.assertIn("text.length > 10000", source)
            self.assertIn("textContent", source)

        self.assertIn("config?.canonicalLocale === 'es'", loader)
        self.assertIn("config?.defaultLocale === 'es'", loader)
        self.assertIn("config.availableLocales.includes(locale)", loader)
        self.assertNotIn("innerHTML = value", loader)
        self.assertNotIn("innerHTML = text", loader)

        self.assertIn("const CONTACT_CATALOG = Object.freeze({", contact)
        self.assertIn("safePublicText(payload.data.question)", contact)
        self.assertNotIn("question.innerHTML", contact)

        package = json.loads(read("package.json"))
        self.assertEqual(
            package["scripts"]["test:i18n-dynamic-runtime"],
            "python3 -m unittest tests/test_public_i18n_dynamic_runtime.py",
        )
        self.assertIn(
            "npm run test:i18n-dynamic-runtime",
            package["scripts"]["test:integration"],
        )


if __name__ == "__main__":
    unittest.main()
