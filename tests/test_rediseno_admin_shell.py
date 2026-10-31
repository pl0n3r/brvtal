"""Offline accessibility regression for the five admin shell CSS paths in #1363."""

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
CSS_FILES = (
    "discadmin/admin-shell.css",
    "discadmin/admin-design-system.css",
    "discadmin/admin-information-architecture.css",
    "discadmin/admin-modules.css",
    "discadmin/dashboard-v2.css",
)
COMMENTS = re.compile(r"/\*.*?\*/", re.DOTALL)
DECL = re.compile(r"(?:^|[;{])\s*([\w-]+)\s*:\s*([^;{}]*)", re.MULTILINE)
PX = re.compile(r"(\d+(?:\.\d+)?)px\b", re.IGNORECASE)
REDUCED = re.compile(r"@media\s*\(\s*prefers-reduced-motion\s*:\s*reduce\s*\)", re.I)


def violations(source: str, path: str) -> list[str]:
    css = COMMENTS.sub("", source)
    problems = []
    for found in DECL.finditer(css):
        property_name, value = found.group(1).lower(), found.group(2).strip()
        if property_name in ("font", "font-size", "--admin-font-xs"):
            for size in PX.finditer(value):
                if float(size.group(1)) < 12:
                    problems.append(f"{path}: {property_name} below 12px: {size.group(0)}")
        if property_name == "transition" and re.search(r"(?:^|,)\s*all\b", value, re.I):
            problems.append(f"{path}: transition: all")
        if property_name == "outline" and re.match(r"(?:none|0(?:px)?)\b", value, re.I):
            # Reject removed focus indications even if a different rule has focus-visible.
            problems.append(f"{path}: missing visible outline")
    has_motion = any(
        found.group(1).lower().startswith(("transition", "animation"))
        and not re.match(r"\s*none\b", found.group(2), re.I)
        for found in DECL.finditer(css)
    )
    if (has_motion or "@keyframes" in css) and not REDUCED.search(css):
        problems.append(f"{path}: motion without prefers-reduced-motion")
    if "@keyframes" in css and not re.search(r"\banimation\s*:\s*none\b", css, re.I):
        problems.append(f"{path}: animated feedback without disable path")
    return problems


class RedisenoAdminShellTests(unittest.TestCase):
    def test_criterio_principal(self) -> None:
        errors = []
        sources = {}
        for relative in CSS_FILES:
            path = ROOT / relative
            self.assertTrue(path.is_file(), f"Missing declared CSS: {relative}")
            source = path.read_text(encoding="utf-8")
            self.assertTrue(source.strip(), relative)
            sources[relative] = source
            errors.extend(violations(source, relative))
        self.assertFalse(errors, "; ".join(errors))

        shell = sources[CSS_FILES[0]]
        design = sources[CSS_FILES[1]]
        nav = sources[CSS_FILES[2]]
        feedback = sources[CSS_FILES[3]]
        dashboard = sources[CSS_FILES[4]]
        self.assertIn("--admin-font-xs:12px", design)
        self.assertIn("min-height:44px", shell)
        self.assertIn("min-height:44px", nav)
        self.assertIn("min-height:44px", feedback)
        self.assertIn("min-height:44px", dashboard)
        self.assertIn(":focus-visible", shell)
        self.assertIn(":focus-visible", feedback)
        self.assertIn("animation:none!important", feedback)
        self.assertIn("animation:none!important", design)
        self.assertIn("font-variant-numeric:tabular-nums", dashboard)
        self.assertIn("text-wrap:balance", dashboard)
        self.assertIn("text-wrap:pretty", dashboard)
        self.assertIn("scale(.96)", design)
        self.assertIn("scale(.96)", dashboard)
        # Also exercise the failure behavior in the sole coordinated AC-01 test.
        self.verify_negative_cases()

    def verify_negative_cases(self) -> None:
        invalid = {
            "size": ("p{font-size:11px}", "below 12px"),
            "shorthand": ("p{font:700 10px/1.5 Arial}", "below 12px"),
            "token": (":root{--admin-font-xs:11px}", "below 12px"),
            "transition": ("p{transition: all .2s}", "transition: all"),
            "outline": ("p:focus{outline:none}", "missing visible outline"),
            "outline0": ("p:focus{outline:0}", "missing visible outline"),
            "motion": ("p{animation:pulse .8s}", "without prefers-reduced-motion"),
            "keyframe": (
                "@keyframes pulse{from{opacity:0}to{opacity:1}}"
                "@media(prefers-reduced-motion:reduce){p{opacity:1}}",
                "without disable path",
            ),
        }
        for name, (source, expected) in invalid.items():
            errors = violations(source, name)
            self.assertTrue(any(expected in error for error in errors), (name, errors))
        self.assertEqual([], violations("p{font:700 12px/1.4 Arial;transition:opacity .2s}", "good"))
        self.assertEqual([], violations("/* font-size:9px */p{font-size:12px}", "comment"))

    def test_rechaza_cambios_inseguros(self) -> None:
        self.verify_negative_cases()


if __name__ == "__main__":
    unittest.main()
