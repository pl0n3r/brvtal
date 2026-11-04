#!/usr/bin/env python3
"""Contract for the Cromo layer: a WebGL chrome backdrop over Concept 05.

The layer is progressive enhancement. These checks keep it from regressing
into a hard dependency: every visual rule is gated by `html.cromo`, the class
is only added after the shader compiled, and constrained devices, Save-Data and
reduced motion never pay for or are forced into the effect.
"""
from __future__ import annotations

import hashlib
import re
import struct
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSS = (ROOT / "css/public-cromo.css").read_text(encoding="utf-8")
JS = (ROOT / "js/public-cromo.js").read_text(encoding="utf-8")
HOME = (ROOT / "config/public_home.php").read_text(encoding="utf-8")
FONT = ROOT / "assets/fonts/BrokenGlass.ttf"
LICENSE = ROOT / "assets/fonts/BrokenGlass-LICENSE.txt"
CSP = (ROOT / ".htaccess").read_text(encoding="utf-8")


def selectors(css: str) -> list[str]:
    stripped = re.sub(r"/\*.*?\*/", "", css, flags=re.S)
    stripped = re.sub(r"@font-face\s*\{[^{}]*\}", "", stripped, flags=re.S)
    found: list[str] = []
    for match in re.finditer(r"([^{}@]+)\{", stripped):
        prelude = match.group(1).strip()
        at_rule = re.match(r"(media|keyframes)\b", prelude)
        if prelude and not at_rule and not re.fullmatch(r"(from|to|\d+%)", prelude):
            found.extend(part.strip() for part in prelude.split(","))
    return found


class CromoLayerTests(unittest.TestCase):
    def test_every_rule_is_gated_by_the_cromo_class(self) -> None:
        for selector in selectors(CSS):
            self.assertTrue(
                selector.startswith("html.cromo"),
                f"ungated selector would change the non-WebGL look: {selector}",
            )

    def test_class_is_added_only_after_shader_and_context_checks(self) -> None:
        for needle in (
            "getContext('webgl'",
            "COMPILE_STATUS",
            "LINK_STATUS",
            "root.classList.add('cromo')",
            "webglcontextlost",
        ):
            self.assertIn(needle, JS)
        self.assertLess(JS.index("COMPILE_STATUS"), JS.index("root.classList.add('cromo')"))

    def test_constrained_and_reduced_motion_paths_are_respected(self) -> None:
        for needle in (
            "saveData",
            "deviceMemory",
            "hardwareConcurrency",
            "prefers-reduced-motion: reduce",
            "document.hidden",
        ):
            self.assertIn(needle, JS)
        self.assertIn("prefers-reduced-motion:reduce", CSS)

    def test_accent_follows_theme_studio_red(self) -> None:
        self.assertIn("'--red'", JS)
        self.assertIn("var(--red", CSS)

    def test_canvas_is_decorative_and_non_interactive(self) -> None:
        self.assertIn("aria-hidden", JS)
        self.assertRegex(CSS, r"#cromoGL\{[^}]*pointer-events:none")

    def test_dark_hero_plates_do_not_use_important(self) -> None:
        self.assertNotIn("!important", CSS)
        self.assertIn("html.cromo body main#top .home-phase-a-hero .hero-copy .hero-sub", CSS)

    def test_title_entrance_never_hides_text(self) -> None:
        self.assertNotIn("opacity:0", CSS)
        self.assertNotIn("clip-path", CSS)
        self.assertNotIn("visibility:hidden", CSS)

    def test_home_injects_assets_once(self) -> None:
        self.assertEqual(HOME.count("css/public-cromo.css"), 2)
        self.assertEqual(HOME.count("js/public-cromo.js"), 2)
        self.assertIn("defer", HOME[HOME.rindex("js/public-cromo.js"):][:60])

    def test_version_bumped_for_deploy_bound_change(self) -> None:
        version = re.search(
            r"BRVTAL_APP_VERSION = '([^']+)'",
            (ROOT / "config/version.php").read_text(encoding="utf-8"),
        )
        package = re.search(
            r'"version": "([^"]+)"', (ROOT / "package.json").read_text(encoding="utf-8")
        )
        self.assertIsNotNone(version)
        self.assertEqual(version.group(1), package.group(1))


    def test_broken_glass_is_original_and_hosted_with_license(self) -> None:
        self.assertTrue(FONT.is_file(), "Broken Glass must be served locally")
        data = FONT.read_bytes()
        self.assertEqual(data[:4], bytes((0, 1, 0, 0)))
        self.assertEqual(
            hashlib.sha256(data).hexdigest(),
            "ac64e57f8ce4ea8a4a4e05d6b42e10e8d34f9668600573df7ba9a985af2e9993",
        )
        self.assertTrue(LICENSE.is_file())
        self.assertIn("This font is in the public domain.", LICENSE.read_text(encoding="utf-8"))
        face = re.search(r"@font-face\s*\{([^{}]*)\}", CSS)
        self.assertIsNotNone(face)
        self.assertIn('font-family:"Broken Glass"', face.group(1))
        self.assertIn('src:url("../assets/fonts/BrokenGlass.ttf") format("truetype")', face.group(1))
        self.assertIn("font-display:swap", face.group(1))
        self.assertIn("font-src 'self'", CSP)

    def test_broken_glass_font_has_accents_and_spanish_punctuation(self) -> None:
        """Read the Unicode TrueType cmap without a nonstandard test dependency."""
        data = FONT.read_bytes()
        u16 = lambda offset: struct.unpack_from(">H", data, offset)[0]
        count = u16(4)
        table_offsets = {
            data[12 + 16 * i:16 + 16 * i]:
            struct.unpack_from(">I", data, 20 + 16 * i)[0]
            for i in range(count)
        }
        cmap = table_offsets[b"cmap"]
        records = [
            struct.unpack_from(">HHI", data, cmap + 4 + 8 * i)
            for i in range(u16(cmap + 2))
        ]
        subtable = next(
            cmap + offset for platform, encoding, offset in records
            if platform == 3 and encoding == 1 and u16(cmap + offset) == 4
        )
        segments = u16(subtable + 6) // 2
        ends = subtable + 14
        starts = ends + 2 * segments + 2
        deltas = starts + 2 * segments
        ranges = deltas + 2 * segments

        def glyph(codepoint: int) -> int:
            for i in range(segments):
                first, last = u16(starts + 2 * i), u16(ends + 2 * i)
                if first <= codepoint <= last:
                    delta = struct.unpack_from(">h", data, deltas + 2 * i)[0]
                    range_offset = u16(ranges + 2 * i)
                    if not range_offset:
                        return (codepoint + delta) & 0xFFFF
                    index = u16(ranges + 2 * i + range_offset + 2 * (codepoint - first))
                    return (index + delta) & 0xFFFF if index else 0
            return 0

        for character in "BRVTALÁÉÍÓÚáéíóúñÑ¿¡":
            self.assertNotEqual(glyph(ord(character)), 0, f"missing glyph {character}")

    def test_broken_glass_is_only_used_by_cromo_display_headings(self) -> None:
        no_face = re.sub(r"@font-face\s*\{[^{}]*\}", "", CSS, flags=re.S)
        no_comments = re.sub(r"/\*.*?\*/", "", no_face, flags=re.S)
        used = []
        for match in re.finditer(r"([^{}]+)\{([^{}]*)\}", no_comments):
            if 'font-family:"Broken Glass"' in match.group(2):
                used.extend(s.strip() for s in match.group(1).split(","))
        self.assertEqual(set(used), {
            "html.cromo .home-phase-a-hero .hero-title",
            "html.cromo main#top section.scene h2",
        })
        self.assertNotRegex(CSS, r"html\.cromo\s+body\s*\{[^}]*font-family")

    def test_mobile_wordmark_has_responsive_cromo_only_width(self) -> None:
        self.assertIn("@media(max-width:600px)", CSS)
        mobile = CSS.split("/* Compact display glyphs fill the mobile hero")[1]
        self.assertIn('html.cromo [data-concept="05"] .home-phase-a-hero .hero-title{', mobile)
        for rule in (
            "width:100%", "max-width:100%", "min-width:0",
            "font-size:clamp(104px,41vw,190px)", "word-break:normal",
            "overflow-wrap:normal", "white-space:nowrap",
        ):
            self.assertIn(rule, mobile)

if __name__ == "__main__":
    unittest.main()
