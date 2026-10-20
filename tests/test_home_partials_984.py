"""Lossless public-home decomposition contract for BRVTAL #984."""
from __future__ import annotations

import http.client
import os
import re
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
from urllib.parse import urlsplit
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
NAMES = (
    "nav", "hero", "experience", "nights", "artists",
    "sound", "memories", "journal", "connected", "footer",
)
MARKER = "<!-- BRVTAL-HOME-PARTIAL:{} -->"


def render_static_composition() -> str:
    html = (ROOT / "index.html").read_text(encoding="utf-8")
    for name in NAMES:
        marker = MARKER.format(name)
        if html.count(marker) != 1:
            raise ValueError(f"Missing or repeated fixed partial marker: {name}")
        partial = ROOT / "partials" / "home" / f"{name}.html"
        html = html.replace(marker, partial.read_text(encoding="utf-8"))
    return html



def compose_without_owner_leaf_markup(html: str) -> str:
    """Keep #984 lossless outside the explicitly owned #971 and #973 regions.

    #971 owns Events/Artists (02/03) and #973 owns Journal/Connected (06/07).
    Missing/duplicate required sections still fail; untouched DOM remains
    byte-exact against the original #984 fixture.
    """
    for section_id in ("events", "artists", "transmissions"):
        pattern = re.compile(
            rf'<section\\b[^>]*\\bid="{section_id}"[^>]*>.*?</section>',
            flags=re.DOTALL,
        )
        html, replacements = pattern.subn(
            f"<!-- owner-edited {section_id} partial -->", html
        )
        if replacements != 1:
            raise AssertionError(
                f"Expected exactly one editable {section_id} section, found {replacements}"
            )

    connected = re.compile(
        r'<section\\b[^>]*\\bid="connected"[^>]*>.*?</section>',
        flags=re.DOTALL,
    )
    html, connected_sections = connected.subn("", html)
    if connected_sections > 1:
        raise AssertionError("Connected region must not be duplicated")
    # #984 froze a blank Connected partial; #973 adds a real section, which
    # may contribute one extra newline at the original fixed marker boundary.
    html = re.sub(
        r"(<!-- owner-edited transmissions partial -->)\\s+(?=    </div>)",
        lambda match: match.group(1) + "\n\n",
        html,
    )
    return html


class HomePartials984Tests(unittest.TestCase):
    def test_existing_php_public_home_contracts(self) -> None:
        php = (ROOT / "index.php").read_text(encoding="utf-8")
        self.assertIn("$homePartialPaths = [", php)
        self.assertIn("substr_count($html, $marker) !== 1", php)
        self.assertIn("http_response_code(503)", php)
        self.assertIn("brvtal_public_render_next_experience", php)
        self.assertIn("brvtal_public_optimize_home_images", php)
        self.assertIn("brvtalPublicApplyLocalizedDocument", php)
        self.assertNotIn("$_GET[", php[php.index("$homePartialPaths = ["):php.index("foreach ($homePartialPaths")])
        self.assertIsNotNone(shutil.which("php"), "PHP required: cannot certify public-home contracts")
        check = subprocess.run(
            ["php", "-l", "index.php"], cwd=ROOT,
            capture_output=True, text=True, check=False,
        )
        self.assertEqual(check.returncode, 0, check.stdout + check.stderr)
        # Existing PHP-rendering regressions must still execute against the
        # composed HTML. Static assertions cannot stand in for these tests.
        from importlib.util import module_from_spec, spec_from_file_location
        targets = (
            ("test_public_concept05_hero.py", "Concept05HeroTests",
             "test_php_next_experience_escapes_regex_replacement_tokens"),
            ("test_public_concept05_owner_reference_v2.py",
             "PublicConcept05OwnerReferenceV2Tests",
             "test_hero_static_copy_respects_route_locale_and_keeps_cms_hook"),
            ("test_public_i18n_contract.py", "PublicI18nContractTests",
             "test_selector_owns_document_locale_after_phase_one_selector"),
        )
        for filename, klass, method in targets:
            with self.subTest(contract=filename, method=method):
                spec = spec_from_file_location(
                    "home984_" + filename.replace(".py", ""), ROOT / "tests" / filename
                )
                self.assertIsNotNone(spec)
                self.assertIsNotNone(spec.loader)
                module = module_from_spec(spec)
                spec.loader.exec_module(module)
                suite = unittest.defaultTestLoader.loadTestsFromName(
                    klass + "." + method, module
                )
                self.assertEqual(suite.countTestCases(), 1)
                outcome = unittest.TestResult()
                suite.run(outcome)
                self.assertTrue(outcome.wasSuccessful(), outcome.errors + outcome.failures)

    def test_rendered_home_matches_pre_refactor_baseline(self) -> None:
        baseline = (ROOT / "tests" / "fixtures" / "home-partials-984-baseline.html").read_text(encoding="utf-8")
        # Preserve all unchanged #984 DOM; only owner-approved #971 (02/03)
        # and #973 (06/07) regions may differ from this historical baseline.
        composed = render_static_composition()
        self.assertEqual(
            compose_without_owner_leaf_markup(composed),
            compose_without_owner_leaf_markup(baseline),
        )
        # Execute the loader extracted from the actual PHP entrypoint, not a
        # duplicate Python implementation. This runs with synthetic files only.
        php = (ROOT / "index.php").read_text(encoding="utf-8")
        start = php.index("$emitHomeUnavailable = static function")
        end = php.index("try {\n    $nextExperience", start)
        program = "$seo = [];\n$analytics = '';\n" + php[start:end].rstrip() + "\necho $html;\n"
        run = subprocess.run(
            ["php", "-r", program], cwd=ROOT,
            capture_output=True, text=True, timeout=30, check=False,
        )
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertEqual(run.stdout, composed)
        self.assertIn('<main id="top">', baseline)
        self.assertIn('data-dynamic="artists"', baseline)
        self.assertIn('data-dynamic="events"', baseline)
        self.assertIn('data-dynamic="sets"', baseline)
        self.assertIn('data-dynamic="media"', baseline)

    def test_fixed_partial_composition_preserves_order(self) -> None:
        source = (ROOT / "index.html").read_text(encoding="utf-8")
        positions = [source.index(MARKER.format(name)) for name in NAMES]
        self.assertEqual(positions, sorted(positions))
        self.assertTrue(all(source.count(MARKER.format(name)) == 1 for name in NAMES))
        self.assertEqual(source.count("<!-- BRVTAL-HOME-PARTIAL:"), len(NAMES))
        connected = (ROOT / "partials" / "home" / "connected.html").read_text(encoding="utf-8")
        self.assertEqual(connected.count('id="connected"'), 1)
        self.assertEqual(connected.count('data-index="07"'), 1)
        self.assertEqual(connected.count('data-connected-node="'), 5)
        self.assertNotIn("data-connected-count>0<", connected)

        # Probe the real entrypoint loader in an isolated fixture directory.
        # It must fail closed if any static piece disappears or is ambiguous.
        from tempfile import TemporaryDirectory
        with TemporaryDirectory(prefix="brvtal-home984-") as scratch:
            root = Path(scratch)
            (root / "partials" / "home").mkdir(parents=True)
            original = (ROOT / "index.html").read_text(encoding="utf-8")
            (root / "index.html").write_text(original, encoding="utf-8")
            for name in NAMES:
                contents = (ROOT / "partials" / "home" / f"{name}.html").read_text(encoding="utf-8")
                (root / "partials" / "home" / f"{name}.html").write_text(
                    contents, encoding="utf-8"
                )
            entrypoint = (ROOT / "index.php").read_text(encoding="utf-8")
            start = entrypoint.index("$emitHomeUnavailable = static function")
            end = entrypoint.index("try {\n    $nextExperience", start)
            # Capture the CLI response code at shutdown; an exit message alone
            # can mask an accidental HTTP 200 from a broken failure branch.
            status_report = (
                "register_shutdown_function(static function (): void { "
                "fwrite(STDERR, 'HTTP_STATUS=' . http_response_code() . chr(10)); "
                "});\n"
            )
            unavailable_stubs = (
                "$seo = [];\n$analytics = '';\n"
                "function brvtal_public_unavailable_seo($seo) { return $seo; }\n"
                "function brvtal_public_unavailable_page($seo, $analytics) { "
                "return 'Public home temporarily unavailable'; }\n"
            )
            script = status_report + unavailable_stubs + entrypoint[start:end] + "\necho $html;\n"
            good = subprocess.run(
                ["php", "-r", script], cwd=root,
                capture_output=True, text=True, timeout=30, check=False,
            )
            self.assertEqual(good.returncode, 0, good.stderr)
            baseline = (ROOT / "tests" / "fixtures" / "home-partials-984-baseline.html").read_text(encoding="utf-8")
            self.assertEqual(good.stdout, render_static_composition())
            # Missing physical partial cannot silently remove the hero.
            hero = root / "partials" / "home" / "hero.html"
            hero.unlink()
            missing = subprocess.run(
                ["php", "-r", script], cwd=root,
                capture_output=True, text=True, timeout=30, check=False,
            )
            self.assertEqual(missing.stdout, "Public home temporarily unavailable")
            self.assertIn("HTTP_STATUS=503", missing.stderr)
            self.assertNotIn("RAVE TILL GRAVE</span>", missing.stdout)
            # Restore the file, then inject a duplicate marker.
            hero.write_text(
                (ROOT / "partials" / "home" / "hero.html").read_text(encoding="utf-8"),
                encoding="utf-8",
            )
            (root / "index.html").write_text(
                original.replace(
                    MARKER.format("hero"),
                    MARKER.format("hero") + MARKER.format("hero"),
                ),
                encoding="utf-8",
            )
            duplicate = subprocess.run(
                ["php", "-r", script], cwd=root,
                capture_output=True, text=True, timeout=30, check=False,
            )
            self.assertEqual(duplicate.stdout, "Public home temporarily unavailable")
            self.assertIn("HTTP_STATUS=503", duplicate.stderr)
            self.assertNotIn("<main", duplicate.stdout)
            # An unknown placeholder must not escape into the public response.
            (root / "index.html").write_text(
                original + "\n<!-- BRVTAL-HOME-PARTIAL:unexpected -->",
                encoding="utf-8",
            )
            unknown = subprocess.run(
                ["php", "-r", script], cwd=root,
                capture_output=True, text=True, timeout=30, check=False,
            )
            self.assertEqual(unknown.stdout, "Public home temporarily unavailable")
            self.assertIn("HTTP_STATUS=503", unknown.stderr)
            self.assertNotIn("BRVTAL-HOME-PARTIAL", unknown.stdout)
            # A known marker nested inside a late partial is also forbidden:
            # it must not escape just because its own allowlist slot passed.
            (root / "index.html").write_text(original, encoding="utf-8")
            footer = root / "partials" / "home" / "footer.html"
            footer.write_text(
                footer.read_text(encoding="utf-8")
                + "\n" + MARKER.format("hero"),
                encoding="utf-8",
            )
            nested = subprocess.run(
                ["php", "-r", script], cwd=root,
                capture_output=True, text=True, timeout=30, check=False,
            )
            self.assertEqual(nested.stdout, "Public home temporarily unavailable")
            self.assertIn("HTTP_STATUS=503", nested.stderr)
            self.assertNotIn("BRVTAL-HOME-PARTIAL", nested.stdout)

    def test_direct_static_home_is_canonical_redirect(self) -> None:
        """Direct /index.html must not publish the unrendered partial markers."""
        htaccess = (ROOT / ".htaccess").read_text(encoding="utf-8")
        host_redirect = "RewriteRule ^ https://www.brvtal.com.co%{REQUEST_URI} [R=301,L,NE]"
        condition = "RewriteCond %{IS_SUBREQ} =false"
        redirect = r"RewriteRule ^index\.html$ / [R=301,L,NE,QSD]"
        self.assertIn("DirectoryIndex index.php\n", htaccess)
        self.assertEqual(htaccess.count(condition), 1)
        # Raw THE_REQUEST patterns do not recognize encoded spellings of index.html.
        self.assertNotIn(r"RewriteCond %{THE_REQUEST} \s/+index\.html", htaccess)
        self.assertEqual(htaccess.count(redirect), 1)
        self.assertLess(htaccess.index(host_redirect), htaccess.index(condition))
        self.assertLess(htaccess.index(condition), htaccess.index(redirect))
        self.assertLess(htaccess.index(redirect), htaccess.index("RewriteRule ^api/public/?$"))
        # Keep the canonical localized URL and entity/preview routes intact.
        for existing in (
            "RewriteRule ^en$ index.php?locale=en [L,QSA,NC]",
            "RewriteRule ^preview/([a-f0-9]{48})/?$ preview.php?token=$1 [L,QSA,NC]",
        ):
            self.assertIn(existing, htaccess)

    def test_direct_static_home_redirect_http(self) -> None:
        """Opt-in Apache HTTP regression against the real .htaccess."""
        if os.environ.get("BRVTAL_APACHE_HTTP_TEST") != "1":
            self.skipTest("Set BRVTAL_APACHE_HTTP_TEST=1 for Apache HTTP")
        httpd = shutil.which("httpd") or shutil.which("apache2")
        self.assertIsNotNone(httpd, "Apache executable required")
        modules = ("mpm_event", "authz_core", "dir", "rewrite")
        roots = (
            Path("/usr/lib/apache2/modules"), Path("/usr/lib64/httpd/modules"),
            Path("/usr/lib/httpd/modules"),
            Path("/opt/homebrew/opt/httpd/lib/httpd/modules"),
            Path("/usr/local/opt/httpd/lib/httpd/modules"),
        )
        module_root = next((p for p in roots if all(
            (p / f"mod_{m}.so").is_file() for m in modules
        )), None)
        self.assertIsNotNone(module_root, "Apache modules unavailable")
        with tempfile.TemporaryDirectory(prefix="brvtal-http984-") as scratch:
            root = Path(scratch)
            (root / ".htaccess").write_text(
                (ROOT / ".htaccess").read_text(encoding="utf-8"), encoding="utf-8"
            )
            (root / "index.php").write_text("COMPOSED PHP HOME", encoding="utf-8")
            (root / "index.html").write_text(
                "UNEXPANDED <!-- BRVTAL-HOME-PARTIAL:hero -->", encoding="utf-8"
            )
            with socket.socket() as s:
                s.bind(("127.0.0.1", 0))
                port = s.getsockname()[1]
            config = root / "httpd.conf"
            config.write_text("\n".join((
                f'ServerRoot "{root}"', "ServerName localhost",
                f'PidFile "{root / "pid"}"', f"Listen 127.0.0.1:{port}",
                *(f"LoadModule {m}_module {module_root / f'mod_{m}.so'}" for m in modules),
                f'DocumentRoot "{root}"', f'<Directory "{root}">',
                "Options FollowSymLinks", "AllowOverride All",
                "Require all granted", "</Directory>",
                f'ErrorLog "{root / "error.log"}"', "LogLevel warn", "",
            )), encoding="utf-8")
            syntax = subprocess.run(
                [httpd, "-t", "-f", str(config)],
                capture_output=True, text=True, check=False, timeout=15,
            )
            self.assertEqual(0, syntax.returncode, syntax.stderr)
            server = subprocess.Popen(
                [httpd, "-f", str(config), "-DFOREGROUND"],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            )
            try:
                for _ in range(60):
                    if server.poll() is not None:
                        self.fail("Apache exited before accepting HTTP")
                    try:
                        c = http.client.HTTPConnection("127.0.0.1", port, timeout=1)
                        c.request("GET", "/")
                        response = c.getresponse()
                        page = response.read()
                        c.close()
                        break
                    except OSError:
                        time.sleep(0.05)
                else:
                    self.fail("Apache did not become ready")
                self.assertEqual(200, response.status)
                self.assertEqual(b"COMPOSED PHP HOME", page)
                for path in (
                    "/index.html", "/index.html?ref=1",
                    "/%69ndex.html", "/ind%65x.html", "/%69ndex.html?ref=1",
                ):
                    with self.subTest(uri=path):
                        c = http.client.HTTPConnection("127.0.0.1", port, timeout=2)
                        try:
                            c.request("GET", path)
                            res = c.getresponse()
                            location = res.getheader("Location")
                            res.read()
                            self.assertEqual(301, res.status)
                            self.assertIsNotNone(location)
                            parsed = urlsplit(location)
                            self.assertEqual("/", parsed.path)
                            self.assertEqual("", parsed.query)
                            if parsed.netloc:
                                self.assertEqual(f"127.0.0.1:{port}", parsed.netloc)
                        finally:
                            c.close()
            finally:
                server.terminate()
                try:
                    server.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait(timeout=5)

    def test_home_partial_ownership_map_is_disjoint(self) -> None:
        mapping = (ROOT / "docs" / "reference" / "home-partials-map.md").read_text(encoding="utf-8")
        for name in NAMES:
            self.assertEqual(mapping.count(f"`{name}.html`"), 1, name)
        self.assertIn("#969", mapping)
        self.assertIn("#968", mapping)
        self.assertIn("#970", mapping)
        self.assertIn("#971", mapping)
        self.assertIn("#972", mapping)
        self.assertIn("#973", mapping)
        self.assertIn("#974", mapping)


if __name__ == "__main__":
    unittest.main()
