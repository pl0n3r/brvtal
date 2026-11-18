#!/usr/bin/env python3
"""BRVTAL #1134: regresión sintética de Theme Studio S32-R010…R014.

Prueba el transformador real de Concept 05 en un VM Node sin navegador,
credenciales, red ni sesiones. La matriz NO_VERIFICABLE solo aporta alcance;
el resultado nunca acredita deploy, observación productiva ni live.
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
SPEC = ROOT / "docs/BRVTAL-SPEC.md"
STUDIO = ROOT / "discadmin/theme-studio-v2.js"
RUNTIME = ROOT / "js/public-theme-runtime.js"
E2E = ROOT / "tests/e2e/discadmin-theme-studio-concept05.spec.mjs"
CLAUSES = tuple(f"S32-R{number:03}" for number in range(10, 15))
UNKNOWN = "UNKNOWN"

# Evaluate the actual JS file in a constrained VM and use its original reset
# and public theme-projection functions, without DOM, storage or network I/O.
NODE_PROBE = r"""
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const source = fs.readFileSync('discadmin/theme-studio-v2.js', 'utf8');
const final = source.lastIndexOf('})();');
if (final < 0 || source.slice(final + 5).trim() !== '') {
  throw new Error('UNKNOWN: Theme Studio closure could not be isolated');
}
const instrumentation = [
  'globalThis.__synthetic = {',
  '  visual: CONCEPT05_VISUAL,',
  '  reset: (theme, groups) => {',
  '    const copy = JSON.parse(JSON.stringify(theme));',
  '    for (const group of groups) applyConcept05Section(copy, group);',
  '    return copy;',
  '  },',
  '  preview: concept05PreviewTheme,',
  '};'
].join('\n') + '\n';
const sandbox = {
  state: { theme: JSON.parse(JSON.stringify(input)) },
  window: { addEventListener() {} },
  document: { addEventListener() {} },
};
vm.runInNewContext(source.slice(0, final) + instrumentation + source.slice(final),
  sandbox, {timeout: 1500, filename: 'theme-studio-v2.js'});
const contract = sandbox.__synthetic;
const full = contract.reset(input, ['colors','typography','navigation','effects']);
const palette = contract.reset(input, ['colors']);
const type = contract.reset(input, ['typography']);
const accent = JSON.parse(JSON.stringify(full));
accent.colors.accent = '#C6FF33';
process.stdout.write(JSON.stringify({
  visual:contract.visual, reset:full, palette, type,
  projected:contract.preview(full), accent:contract.preview(accent),
}));
"""


def theme_fixture() -> dict:
    """Identidades ficticias y rutas no existentes, nunca material productivo."""
    return {
        "name": "SYNTHETIC THEME", "slug": "synthetic-theme",
        "branding": {
            "siteName": "SYNTHETIC BRAND", "tagline": "FIXTURE ONLY",
            "logo": "/synthetic-main.svg", "mobileLogo": "/synthetic-mobile.svg",
            "favicon": "/synthetic-favicon.png", "preloaderLogo": "/synthetic-loader.svg",
            "wordmark": "/synthetic-wordmark.svg",
        },
        "colors": {"bg": "#101010", "surface": "#121212", "text": "#EEEEEE",
                   "muted": "#999999", "primary": "#A10000", "accent": "#00FF00",
                   "border": "#333333"},
        "typography": {"display": "Arial, sans-serif", "body": "Arial, sans-serif",
                       "mono": "monospace", "h1": "500px", "bodySize": "96px",
                       "tracking": "3em"},
        "navigation": {"fixed": False, "transparentHero": False, "blur": False,
                       "menuStyle": "custom", "logoPosition": "right",
                       "sceneIndicator": True, "soundToggle": False},
        "effects": {"grain": False, "scanlines": False, "glitch": False,
                    "cursor": False, "magnetic": False, "motion": "unbounded"},
        "sound": {"enabled": False},
        "seo": {"siteTitle": "SYNTHETIC LEGACY"},
        "layout": {"x": 900, "zIndex": 9999, "breakpoints": [123]},
        "legacy": {"preserve": "FIXTURE_LEGACY"},
    }


def matrix_rows(content: str) -> dict[str, list[str]]:
    """Exact row cardinality and columns; duplicates and malformed data fail."""
    found: dict[str, list[str]] = {}
    for line in content.splitlines():
        match = re.match(r"^\| (S32-R0\d\d) · ", line)
        if match is None or match.group(1) not in CLAUSES:
            continue
        key = match.group(1)
        if key in found:
            raise ValueError(f"UNKNOWN: duplicate {key}")
        columns = [part.strip() for part in line.strip().strip("|").split("|")]
        if len(columns) != 5 or not columns[1] or not columns[3]:
            raise ValueError(f"UNKNOWN: malformed {key}")
        found[key] = columns
    return found


def source_contract(studio: str, runtime: str) -> dict[str, str]:
    """Never count a missing function/panel as proof of contract compliance."""
    try:
        panel = studio.split("function panel(theme) {", 1)[1].split(
            "function currentTheme() {", 1)[0]
        reset = studio.split("function applyConcept05Section(theme, section) {", 1)[1].split(
            "function duplicate() {", 1)[0]
    except IndexError:
        return {k: UNKNOWN for k in CLAUSES}
    if not all(x in studio for x in (
        "const CONCEPT05_VISUAL =", "function concept05PreviewTheme(theme) {",
    )):
        return {k: UNKNOWN for k in CLAUSES}
    if not all(x in runtime for x in (
        "applyColors(theme.colors)", "applyTypography(theme.typography)",
        "applyNavigation(theme.navigation, theme.sound)",
        "applyEffects(theme.effects)", "applyBranding(theme.branding)",
    )):
        return {k: UNKNOWN for k in CLAUSES}
    return {"S32-R010": reset, "S32-R011": reset,
            "S32-R012": panel, "S32-R013": panel, "S32-R014": panel}


def offline_probe(input_theme: dict) -> dict:
    node = shutil.which("node")
    if not node:
        raise AssertionError("UNKNOWN: node unavailable for executable behavior")
    result = subprocess.run(
        [node, "-e", NODE_PROBE], input=json.dumps(input_theme),
        cwd=ROOT, capture_output=True, text=True, timeout=20, check=False,
    )
    if result.returncode:
        raise AssertionError("UNKNOWN: Theme Studio sandbox failed: " + result.stderr[-1500:])
    try:
        return json.loads(result.stdout)
    except json.JSONDecodeError as error:
        raise AssertionError("UNKNOWN: invalid sandbox evidence") from error


class BrechasBrvtal44Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.studio = STUDIO.read_text(encoding="utf-8")
        cls.runtime = RUNTIME.read_text(encoding="utf-8")
        cls.rows = matrix_rows(MATRIX.read_text(encoding="utf-8"))
        cls.contract = source_contract(cls.studio, cls.runtime)
        cls.behavior = offline_probe(theme_fixture())

    def test_contrato_principal(self) -> None:
        """Five S32 clauses exercise bounded reset and real preview projection."""
        base, run = theme_fixture(), self.behavior
        reset, projected, variant = run["reset"], run["projected"], run["accent"]
        studio_panel = self.contract["S32-R013"]
        spec = SPEC.read_text(encoding="utf-8")
        e2e = E2E.read_text(encoding="utf-8")
        self.assertEqual(set(self.rows), set(CLAUSES))
        self.assertEqual(set(self.contract), set(CLAUSES))
        self.assertIn("Concept 05 is the safe reset/default visual system", spec)
        verdicts = {
            "S32-R010": (
                reset["colors"] == run["visual"]["colors"]
                and reset["typography"] == run["visual"]["typography"]
                and reset["navigation"] == run["visual"]["navigation"]
                and reset["effects"] == run["visual"]["effects"]
                and "if (kind !== 'concept05') return;" in self.contract["S32-R010"]
                and "RESET VISUALS TO CONCEPT 05" in studio_panel
            ),
            "S32-R011": (
                all(reset[k] == base[k] for k in
                    ("slug", "branding", "seo", "layout", "legacy"))
                and run["palette"]["typography"] == base["typography"]
                and run["type"]["colors"] == base["colors"]
                and "RESET PALETTE" in studio_panel
                and "RESET TYPE" in studio_panel
                and "expect(reset.seo.description).toBe('KEEP ME')" in e2e
            ),
            "S32-R012": (
                projected["branding"] == variant["branding"]
                and projected["slug"] == variant["slug"] == base["slug"]
                and projected["colors"]["bg"] == variant["colors"]["bg"] == "#050505"
                and projected["colors"]["primary"] == variant["colors"]["primary"] == "#E31B23"
                and projected["colors"]["accent"] == "#B6FF00"
                and variant["colors"]["accent"] == "#C6FF33"
                and "BLACK / PAPER / RED remain the mother identity" in studio_panel
            ),
            "S32-R013": (
                all(marker in studio_panel for marker in (
                    "fontSelectField('display'", "toggleField('grain'",
                    "selectField('motion'", "selectField('menuStyle'",
                ))
                and 'data-theme-tab="seo"' not in studio_panel
                and "textField('analytics'" not in studio_panel
                and "textField('seo'" not in studio_panel
                and "Theme Studio must not expose controls" in spec
            ),
            "S32-R014": (
                set(projected) == {"slug", "branding", "colors", "typography",
                                   "navigation", "effects", "sound"}
                and not any(forbidden in json.dumps(projected) for forbidden in
                            ('"layout"', '"zIndex"', '"breakpoints"', '"legacy"'))
                and all(("textField('" + key + "'") not in studio_panel for key in
                        ("h1", "bodySize", "tracking", "position", "zIndex",
                         "breakpoints", "animationTimeline", "layout"))
                and "fontSelectField('display'" in studio_panel
                and "arbitrary per-element positions" in spec
            ),
        }
        self.assertEqual(set(verdicts), set(CLAUSES))
        for key, is_valid in verdicts.items():
            with self.subTest(clause=key):
                self.assertNotEqual(self.contract[key], UNKNOWN, key)
                self.assertTrue(is_valid, f"{key}: evidencia sintética insuficiente")

    def test_falla_cerrado(self) -> None:
        """Absence, duplicate rows, missing code and injected entropy fail."""
        self.assertEqual(set(matrix_rows("")), set())
        self.assertEqual(set(source_contract("", self.runtime).values()), {UNKNOWN})
        self.assertEqual(set(source_contract(self.studio, "").values()), {UNKNOWN})
        sample = next(line for line in MATRIX.read_text(encoding="utf-8").splitlines()
                      if line.startswith("| S32-R010 · "))
        with self.assertRaisesRegex(ValueError, "duplicate"):
            matrix_rows(sample + "\n" + sample)
        with self.assertRaisesRegex(ValueError, "malformed"):
            matrix_rows("| S32-R010 · bad | oops |")
        for clause, row in self.rows.items():
            with self.subTest(clause=clause):
                self.assertEqual(len(row), 5)
                self.assertIn(row[2], {"NO_VERIFICABLE", "EN_CURSO", "CUBIERTO"})
        self.assertNotIn("layout", self.behavior["projected"])
        self.assertNotIn("breakpoints", self.behavior["projected"])

    def test_sin_datos_reales(self) -> None:
        """Only fictitious local inputs; sandbox forbids network and storage."""
        data = json.dumps(theme_fixture())
        for forbidden in ("@", "Bearer ", "password", "api_key", "private_key", "token="):
            self.assertNotIn(forbidden, data)
        for forbidden in ("fetch(", "https.request", "http.request", "child_process"):
            self.assertNotIn(forbidden, NODE_PROBE)
        self.assertIn("synthetic", data.lower())
        self.assertTrue(STUDIO.is_file() and RUNTIME.is_file())

    def test_estados_unknown_explicitos(self) -> None:
        """Offline contracts never substitute independent live/production evidence."""
        projected = self.behavior["projected"]
        self.assertFalse(set(projected) & {"production", "live", "deployed", "verified"})
        self.assertTrue(all(clause in self.rows for clause in CLAUSES))
        states = {clause: {"code": "LOCAL_SYNTHETIC", "live": UNKNOWN,
                           "production": UNKNOWN, "deploy": UNKNOWN} for clause in CLAUSES}
        for clause, evidence in states.items():
            with self.subTest(clause=clause):
                self.assertEqual(evidence["live"], UNKNOWN)
                self.assertEqual(evidence["production"], UNKNOWN)
                self.assertEqual(evidence["deploy"], UNKNOWN)
                self.assertNotEqual(evidence["code"], "PRODUCTION_VERIFIED")


if __name__ == "__main__":
    unittest.main()
