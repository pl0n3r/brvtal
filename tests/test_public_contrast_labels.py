#!/usr/bin/env python3
"""WCAG 2.1 AA contrast regression contract for Concept 05 public labels.

Reads shipped CSS, checks 4.5:1 with current Theme Studio palette variants,
and verifies modern contrast-color and the legacy theme-derived fallback.
Visual 390/1440 baselines remain unchanged pending owner approval (#1448).
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSS = {
    name: (ROOT / "css" / name).read_text(encoding="utf-8")
    for name in (
        "public-concept05-home.css",
        "public-concept05-nights-artists.css",
        "public-concept05-experience.css",
        "public-concept05-sound-memories.css",
    )
}
MIN_RATIO = 4.5


def declarations(filename: str, selector: str) -> list[dict[str, str]]:
    source = re.sub(r"/\*.*?\*/", "", CSS[filename], flags=re.S)
    found = []
    for match in re.finditer(r"([^{}]+)\{([^{}]*)\}", source, flags=re.S):
        selectors = [part.strip() for part in match.group(1).split(",")]
        if selector not in selectors:
            continue
        found.append({
            prop: value.strip()
            for prop, value in re.findall(r"([\w-]+)\s*:\s*([^;{}]+)", match.group(2))
        })
    return found


def property_value(filename: str, selector: str, property_name: str) -> str:
    for rule in reversed(declarations(filename, selector)):
        if property_name in rule:
            return rule[property_name]
    raise AssertionError(f"Missing {property_name} for {selector} in {filename}")


def rgb(color: str) -> tuple[int, int, int]:
    match = re.fullmatch(r"#([0-9a-fA-F]{6})", color)
    if match is None:
        raise AssertionError(f"Expected CSS sRGB hex: {color!r}")
    val = match.group(1)
    return tuple(int(val[i:i+2], 16) for i in (0, 2, 4))


def luminance(color: tuple[int, int, int]) -> float:
    linear = []
    for channel in color:
        srgb = channel / 255.0
        linear.append(srgb / 12.92 if srgb <= .04045
                      else ((srgb + .055) / 1.055) ** 2.4)
    return sum(x * y for x, y in zip(linear, (.2126, .7152, .0722)))


def contrast(foreground: tuple[int, int, int],
             background: tuple[int, int, int]) -> float:
    hi, lo = sorted((luminance(foreground), luminance(background)), reverse=True)
    return (hi + .05) / (lo + .05)


def mix(primary: tuple[int, int, int], base: tuple[int, int, int], part: float):
    return tuple(round(a * part + b * (1 - part)) for a, b in zip(primary, base))


class Concept05LabelContrastTests(unittest.TestCase):
    def test_theme_studio_red_remains_single_accent_source(self) -> None:
        tokens = (ROOT / "css/public-concept05-tokens.css").read_text(encoding="utf-8")
        home = CSS["public-concept05-home.css"]
        self.assertIn("--c5-signal-red:var(--red", tokens)
        self.assertIn("--c5-a11y-red-bg:var(--c5-signal-red)", home)
        self.assertIn("--c5-a11y-red-fg:contrast-color(var(--c5-signal-red))", home)
        self.assertIn("@supports (color:contrast-color(red))", home)

    def test_45_to_1_minimum_for_theme_palette_and_fallback(self) -> None:
        home = CSS["public-concept05-home.css"]
        fallback = re.search(
            r"--c5-a11y-red-bg:color-mix\(in srgb,var\(--c5-signal-red\) "
            r"(\d+)%,(#[0-9a-fA-F]{6})\)", home
        )
        self.assertIsNotNone(fallback)
        fraction = int(fallback.group(1)) / 100.0
        base = rgb(fallback.group(2))
        fallback_fg = re.search(r"--c5-a11y-red-fg:(#[0-9a-fA-F]{6})", home)
        self.assertIsNotNone(fallback_fg)
        ink = rgb(fallback_fg.group(1))
        for token in ("#ff1717", "#d31822", "#9e0d12", "#e12e34",
                      "#777777", "#ffffff", "#000000"):
            with self.subTest(theme_red=token):
                bg = rgb(token)
                modern_fg = max((rgb("#000000"), rgb("#ffffff")),
                                key=lambda candidate: contrast(candidate, bg))
                self.assertGreaterEqual(contrast(modern_fg, bg), MIN_RATIO)
                self.assertGreaterEqual(contrast(ink, mix(bg, base, fraction)),
                                        MIN_RATIO)

    def test_red_label_text_and_background_all_states(self) -> None:
        pairs = (
            ("public-concept05-home.css",
             '[data-concept="05"] .events .event-ticket'),
            ("public-concept05-nights-artists.css",
             '[data-concept="05"] .c5-night-actions .event-ticket'),
            ("public-concept05-experience.css",
             '[data-concept="05"] .c5-experience-artwork-label'),
            ("public-concept05-sound-memories.css",
             '[data-concept="05"] #sets .set-listen-action'),
        )
        for filename, selector in pairs:
            for state in ("", ":hover", ":focus-visible"):
                name = selector + state
                with self.subTest(file=filename, selector=name):
                    self.assertEqual(property_value(filename, name, "background"),
                                     "var(--c5-a11y-red-bg)")
                    self.assertEqual(property_value(filename, name, "color"),
                                     "var(--c5-a11y-red-fg)")

    def test_open_record_stays_contrasting_on_opaque_plate(self) -> None:
        file = "public-concept05-nights-artists.css"
        for selector in (
            '[data-concept="05"] .c5-night-actions .event-record',
            '[data-concept="05"] [data-c5-past-nights] .c5-night-card .event-record',
        ):
            for state in ("", ":hover", ":focus-visible"):
                name = selector + state
                with self.subTest(selector=name):
                    fg = rgb(property_value(file, name, "color"))
                    bg = rgb(property_value(file, name, "background"))
                    self.assertGreaterEqual(contrast(fg, bg), MIN_RATIO)


if __name__ == "__main__":
    unittest.main()
