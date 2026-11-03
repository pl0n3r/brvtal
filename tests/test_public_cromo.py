#!/usr/bin/env python3
"""Contract for the Cromo layer: a WebGL chrome backdrop over Concept 05.

The layer is progressive enhancement. These checks keep it from regressing
into a hard dependency: every visual rule is gated by `html.cromo`, the class
is only added after the shader compiled, and constrained devices, Save-Data and
reduced motion never pay for or are forced into the effect.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSS = (ROOT / "css/public-cromo.css").read_text(encoding="utf-8")
JS = (ROOT / "js/public-cromo.js").read_text(encoding="utf-8")
HOME = (ROOT / "config/public_home.php").read_text(encoding="utf-8")


def selectors(css: str) -> list[str]:
    stripped = re.sub(r"/\*.*?\*/", "", css, flags=re.S)
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
                selector.startswith("html.cromo") or selector == "#cromoGL",
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


if __name__ == "__main__":
    unittest.main()
