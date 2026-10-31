"""BRVTAL #1352: public privacy/rights page and universal footer integration."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONFIG = ROOT / "config/public_privacy_rights.php"


def invoke_php(script: str) -> dict:
    result = subprocess.run(
        ["php", "-d", "display_errors=stderr", "-r", script],
        cwd=ROOT, text=True, capture_output=True, timeout=30, check=False,
    )
    if result.returncode != 0:
        raise AssertionError("PHP execution failed: " + result.stderr)
    return json.loads(result.stdout)


class PrivacyRightsPageTests(unittest.TestCase):
    def test_criterio_principal(self) -> None:
        self.assertTrue(CONFIG.is_file())
        php = r"""
require 'config/public_privacy_rights.php';
$settings = ['controller_name' => 'Entidad sintética',
             'controller_email' => 'example@example.invalid',
             'response_time' => 'Plazo pendiente de revisión'];
$html = brvtalPublicPrivacyRightsDocument($settings);
$footer = brvtalPublicPrivacyRightsFooterLink('<html><body><footer>BRVTAL</footer></body></html>');
echo json_encode(['page' => $html, 'footer' => $footer]);
"""
        data = invoke_php(php)
        page = data["page"]
        self.assertIn("Privacidad y mis derechos", page)
        self.assertIn("Entidad sintética", page)
        self.assertIn("mailto:example@example.invalid", page)
        self.assertIn("acceso, corrección o borrado", page)
        self.assertIn("Plazo pendiente de revisión", page)
        self.assertIn("pendiente de aprobación profesional", page)
        self.assertIn('href="/privacy"', data["footer"])
        self.assertEqual(data["footer"].count("data-privacy-rights-link"), 1)

        # The original two-file task must also be wired to a public route and
        # to every response; source-only tests must not conceal a missing route.
        routing = (ROOT / ".htaccess").read_text(encoding="utf-8")
        index = (ROOT / "index.php").read_text(encoding="utf-8")
        localize = (ROOT / "config/public_i18n_routing.php").read_text(encoding="utf-8")
        self.assertIn("index.php?page=privacy", routing)
        self.assertIn("pageRoute === 'privacy'", index)
        self.assertIn("brvtalPublicPrivacyRightsDocument", index)
        self.assertIn("brvtalPublicPrivacyRightsFooterLink", index)
        self.assertIn("'privacy'", localize)

    def test_missing_configuration_fails_closed(self) -> None:
        data = invoke_php(r"""
require 'config/public_privacy_rights.php';
$x = brvtalPublicPrivacyRightsConfiguration([]);
$y = brvtalPublicPrivacyRightsConfiguration(['controller_email' => 'javascript:alert(1)']);
$html = brvtalPublicPrivacyRightsDocument([]);
echo json_encode(['blank' => $x, 'invalid' => $y, 'html' => $html]);
""")
        marker = "[COMPLETAR POR EL DUEÑO]"
        self.assertEqual(set(data["blank"].values()), {marker})
        self.assertEqual(data["invalid"]["controller_email"], marker)
        self.assertGreaterEqual(data["html"].count(marker), 3)
        self.assertNotIn("mailto:", data["html"])

    def test_escaping_and_footer_fallback(self) -> None:
        data = invoke_php(r"""
require 'config/public_privacy_rights.php';
$html = brvtalPublicPrivacyRightsDocument([
  'controller_name' => '<script>alert(1)</script>',
  'response_time' => '<img src=x onerror=alert(1)>']);
$old = brvtalPublicPrivacyRightsFooterLink(
  '<html><body><footer><a href="#" data-footer-privacy hidden>PRIVACY</a></footer></body></html>');
$noFooter = brvtalPublicPrivacyRightsFooterLink('<html><body><main>ok</main></body></html>');
echo json_encode(['html' => $html, 'old' => $old, 'fallback' => $noFooter]);
""")
        self.assertNotIn("<script>alert(1)</script>", data["html"])
        self.assertIn("&lt;script&gt;", data["html"])
        self.assertNotIn("<img src=x", data["html"])
        self.assertEqual(data["old"].count('href="/privacy"'), 1)
        self.assertNotIn(" hidden", data["old"])
        self.assertIn('href="/privacy"', data["fallback"])

    def test_no_unapproved_legal_text(self) -> None:
        source = CONFIG.read_text(encoding="utf-8")
        self.assertIn("Condor #974", source)
        self.assertIn("[COMPLETAR POR EL DUEÑO]", source)
        self.assertNotIn("contact@brvtal.com.co", source)
        self.assertNotIn("30 días hábiles", source)


if __name__ == "__main__":
    unittest.main()
