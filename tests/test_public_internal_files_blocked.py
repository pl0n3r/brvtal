"""Static Apache/LiteSpeed security regression for public HTTP surfaces (#1421)."""

from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[1]
HTACCESS = ROOT / ".htaccess"


class PublicInternalFilesBlockedTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.rules = HTACCESS.read_text(encoding="utf-8")
        cls.denies = []
        for line in cls.rules.splitlines():
            match = re.fullmatch(
                r"\s*RewriteRule\s+(\S+)\s+-\s+\[([^\]]+)\]\s*",
                line,
                re.IGNORECASE,
            )
            if not match:
                continue
            flags = {item.strip().upper() for item in match.group(2).split(",")}
            if {"F", "L"}.issubset(flags):
                cls.denies.append(re.compile(match.group(1), re.IGNORECASE))

    def test_internal_paths_are_denied(self):
        self.assertGreaterEqual(len(self.denies), 2)
        for path in (
            "datos.yml",
            "decisiones.yml",
            "docs/BRVTAL-SPEC.md",
            "docs/nested/file.md",
            "package.json",
            "composer.json",
            "composer.lock",
            "package-lock.json",
            "AGENTS.md",
            "config/bootstrap.php",
            "database/schema.sql",
            "tests/test_public_internal_files_blocked.py",
            "scripts/deploy.sh",
            ".github/workflows/ci.yml",
            ".factory/scripts/coordinar_trabajo.py",
        ):
            with self.subTest(path=path):
                self.assertTrue(
                    any(pattern.match(path) for pattern in self.denies), path
                )

    def test_legitimate_public_routes_remain_allowed(self):
        for path in (
            "", "contact", "discadmin", "discadmin/index.php",
            "api/public.php", "robots.txt", "sitemap.xml", "sitemap.php",
            "indexnow-example-key.txt", "assets/brvtal-logo.jpeg",
            "css/style.css", "js/public-contact.js", "uploads/photo.webp",
        ):
            with self.subTest(path=path):
                self.assertFalse(
                    any(pattern.match(path) for pattern in self.denies), path
                )
        self.assertIn("RewriteRule ^sitemap\\.xml$", self.rules)
        self.assertIn("RewriteRule ^indexnow-", self.rules)

    def test_hsts_and_php_fingerprint_headers(self):
        self.assertRegex(
            self.rules,
            r'(?m)^\s*Header always set Strict-Transport-Security '
            r'"max-age=31536000"\s*$',
        )
        self.assertRegex(
            self.rules, r"(?m)^\s*Header unset X-Powered-By\s*$"
        )
        self.assertRegex(
            self.rules, r"(?m)^\s*Header always unset X-Powered-By\s*$"
        )

    def test_csp_covers_current_site_without_eval(self):
        match = re.search(
            r'(?m)^\s*Header always set Content-Security-Policy "([^"\n]+)"\s*$',
            self.rules,
        )
        self.assertIsNotNone(match, "CSP header must be set on all responses")
        policy = match.group(1)
        directives = {}
        for raw in policy.split(";"):
            fields = raw.strip().split()
            if fields:
                self.assertNotIn(fields[0], directives)
                directives[fields[0]] = fields[1:]
        for name in (
            "default-src", "script-src", "style-src", "font-src", "img-src",
            "media-src", "connect-src", "frame-src", "object-src",
            "base-uri", "form-action", "frame-ancestors",
            "upgrade-insecure-requests",
        ):
            self.assertIn(name, directives)
        self.assertIn("'self'", directives["default-src"])
        self.assertEqual(directives["object-src"], ["'none'"])
        self.assertIn("'unsafe-inline'", directives["script-src"])
        self.assertIn("'unsafe-inline'", directives["style-src"])
        self.assertNotIn("'unsafe-eval'", directives["script-src"])
        self.assertIn("https://fonts.googleapis.com", directives["style-src"])
        self.assertIn("https://fonts.gstatic.com", directives["font-src"])
        self.assertIn("https://www.googletagmanager.com", directives["script-src"])
        self.assertIn("https://www.google-analytics.com", directives["connect-src"])
        self.assertIn("https://w.soundcloud.com", directives["frame-src"])
        self.assertIn("https:", directives["img-src"])
        self.assertIn("blob:", directives["media-src"])


if __name__ == "__main__":
    unittest.main()
