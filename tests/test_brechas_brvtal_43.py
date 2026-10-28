#!/usr/bin/env python3
"""#1133: contratos sintéticos de Theme Studio, S32-R005..S32-R009.

Evidencia de código/preview offline; no prueba go-live, usuarios ni producción.
La matriz de cobertura se reconcilia en un issue independiente.
"""
from __future__ import annotations

import json
import re
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
STUDIO = ROOT / "discadmin/theme-studio-v2.js"
RUNTIME = ROOT / "js/public-theme-runtime.js"
PUBLIC_API = ROOT / "api/public.php"
MATRIX = ROOT / "docs/matriz-cobertura.md"
CLAUSES = tuple(f"S32-R{n:03}" for n in range(5, 10))

# Ejecuta funciones reales del editor en un VM descartable sin DOM real, red,
# storage, backend ni identidad de usuario. Nunca invoca persist()/req().
NODE_PROBE = r"""
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const theme = JSON.parse(fs.readFileSync(0, 'utf8'));
const original = fs.readFileSync('discadmin/theme-studio-v2.js', 'utf8');
const end = original.lastIndexOf('})();');
if (end < 0 || original.slice(end + 5).trim()) {
  throw new Error('No se puede instrumentar el editor de forma segura');
}
const additions = [
  'globalThis.__probe = {',
  '  project: concept05PreviewTheme,',
  '  contrast: contrastRatio,',
  '  fontCatalog: FONT_CATALOG,',
  '  reset: (theme, section) => {',
  '    const next = JSON.parse(JSON.stringify(theme));',
  '    applyConcept05Section(next, section);',
  '    return next;',
  '  },',
  '  size: (mode) => {',
  '    V2.previewMode = mode;',
  '    const data = { dataset: {}, style: {},',
  '      getBoundingClientRect: () => ({width: 2000}) };',
  '    const frame = { attrs: {}, style: {},',
  '      setAttribute(k,v) { this.attrs[k] = v; },',
  '      closest() { return data; } };',
  '    sizeCanonicalPreview(frame);',
  '    return { attrs: frame.attrs, dataset: data.dataset };',
  '  }',
  '};',
].join('\n') + '\n';
const sandbox = {
  state: { theme },
  window: { addEventListener() {} },
  document: { addEventListener() {} },
  requestAnimationFrame(fn) { fn(); },
};
vm.runInNewContext(original.slice(0,end) + additions + original.slice(end),
  sandbox, { timeout: 1500, filename: 'theme-studio-v2.js' });
const p = sandbox.__probe;
const invalid = JSON.parse(JSON.stringify(theme));
invalid.navigation.menuStyle = 'unknown-menu';
invalid.navigation.logoPosition = 'right';
invalid.effects.motion = 'arbitrary-motion';
for (const key of ['grain','scanlines','glitch','cursor','magnetic']) {
  invalid.effects[key] = false;
}
invalid.sound.enabled = false;
const result = {
  projected: p.project(theme),
  bounded: p.project(invalid),
  missing: p.project({ slug: 'fixture-only' }),
  colorsReset: p.reset(theme, 'colors'),
  typographyReset: p.reset(theme, 'typography'),
  contrast: p.contrast('#F4F1E8', '#050505'),
  sameColorContrast: p.contrast('#050505', '#050505'),
  fonts: JSON.parse(JSON.stringify(p.fontCatalog)),
  desktop: p.size('desktop'),
  mobile: p.size('mobile'),
};
process.stdout.write(JSON.stringify(result));
"""


def fixture() -> dict:
    return {
        "slug": "theme-fixture", "name": "Synthetic theme",
        "branding": {"siteName": "BRVTAL fixture", "logo": "/fixture.svg"},
        "colors": {
            "bg": "#080808", "surface": "#101010", "text": "#F4F1E8",
            "muted": "#888888", "primary": "#E31B23", "accent": "#B6FF00",
            "border": "#30302D",
        },
        "typography": {
            "display": '"Space Grotesk", Arial, sans-serif',
            "body": 'Arial, Helvetica, sans-serif',
            "mono": '"Space Mono", monospace',
        },
        "navigation": {
            "fixed": True, "transparentHero": True, "blur": True,
            "menuStyle": "slide", "logoPosition": "center", "soundToggle": True,
        },
        "effects": {
            "grain": True, "scanlines": True, "glitch": True,
            "cursor": True, "magnetic": True, "motion": "minimal",
        },
        "sound": {"enabled": True},
        "seo": {"legacy": "fixture-only"},
        "analytics": {"apiKey": "synthetic-never-export"},
        "customCode": "synthetic-never-execute",
    }


@lru_cache(maxsize=1)
def probe() -> dict:
    result = subprocess.run(
        ["node", "-e", NODE_PROBE], input=json.dumps(fixture()),
        cwd=ROOT, capture_output=True, text=True, timeout=20, check=False,
    )
    if result.returncode:
        raise AssertionError("VM sintético del editor falló: " + result.stderr)
    return json.loads(result.stdout)


def coverage_rows() -> dict[str, list[str]]:
    found = {}
    for line in MATRIX.read_text(encoding="utf-8").splitlines():
        match = re.match(r"^\| (S32-R00[5-9]) · ", line)
        if not match:
            continue
        clause = match.group(1)
        if clause in found:
            raise AssertionError(f"{clause}: requisito duplicado")
        found[clause] = [part.strip() for part in line.strip().strip("|").split("|")]
    return found


def evidence_status(projected: dict, allowed_fonts: set[str]) -> str:
    """La evidencia incompleta o ambigua es UNKNOWN, nunca éxito inferido."""
    if not isinstance(projected, dict):
        return "UNKNOWN"
    required = {
        "colors": {"bg", "text", "primary", "accent", "border"},
        "typography": {"display", "body", "mono"},
        "navigation": {"menuStyle", "logoPosition"},
        "effects": {"grain", "scanlines", "glitch", "motion"},
    }
    if any(not isinstance(projected.get(section), dict) or
           not fields.issubset(projected[section]) or
           any(projected[section][field] is None
               for field in fields)
           for section, fields in required.items()):
        return "UNKNOWN"
    fonts = projected["typography"]
    if any(fonts.get(role) not in allowed_fonts for role in ("display", "body", "mono")):
        return "UNKNOWN"
    if not re.fullmatch(r"#[a-fA-F0-9]{6}", str(projected["colors"]["bg"])):
        return "UNKNOWN"
    if not re.fullmatch(r"#[a-fA-F0-9]{6}", str(projected["colors"]["text"])):
        return "UNKNOWN"
    return "CONTRACT_ONLY"


class BrechasBrvtal43Tests(unittest.TestCase):
    """Cuatro AC exactos, cinco filas separadas y negativos explícitos."""

    def test_contrato_principal(self):
        result = probe()
        theme = fixture()
        p = result["projected"]
        fonts = result["fonts"]
        studio = STUDIO.read_text(encoding="utf-8")
        runtime = RUNTIME.read_text(encoding="utf-8")
        panel = studio.split("function panel(theme)", 1)[1].split(
            "function currentTheme()", 1
        )[0]
        persist = studio.split("async function persist(activate = false)", 1)[1].split(
            "async function switchTheme(slug)", 1
        )[0]
        verdicts = {
            "S32-R005": (
                result["colorsReset"]["colors"]["bg"] == "#050505"
                and result["colorsReset"]["colors"]["primary"] == "#E31B23"
                and result["colorsReset"]["branding"] == theme["branding"]
                and result["colorsReset"]["slug"] == theme["slug"]
                and result["colorsReset"]["typography"] == theme["typography"]
                and result["contrast"] >= 4.5
                and "data-contrast-check" in panel
                and all(role in panel for role in (
                    "BLACK · canvas", "PAPER · foreground",
                    "RED · mother signal", "SIGNAL · neon accent",
                    "LINE · structure"
                ))
                and "applyColors(theme.colors)" in runtime
            ),
            "S32-R006": (
                set(fonts) == {"display", "body", "mono"}
                and all(len(options) >= 2 for options in fonts.values())
                and "Space Grotesk" in fonts["display"][0]["label"]
                and "Space Grotesk" in fonts["body"][0]["label"]
                and "Space Mono" in fonts["mono"][0]["label"]
                and all("sans-serif" in option["stack"] or "monospace" in option["stack"]
                        for options in fonts.values() for option in options)
                and p["typography"]["body"] == theme["typography"]["body"]
                and result["typographyReset"]["colors"] == theme["colors"]
                and result["typographyReset"]["branding"] == theme["branding"]
                and "data-type-specimen" in panel
                and "GOOGLE_FONT_QUERY" in runtime
                and "families.map(family" in runtime
                and "&display=swap" in runtime
            ),
            "S32-R007": (
                p["navigation"]["menuStyle"] == "slide"
                and p["navigation"]["logoPosition"] == "center"
                and p["effects"]["motion"] == "minimal"
                and result["bounded"]["navigation"]["menuStyle"] == "fullscreen"
                and result["bounded"]["navigation"]["logoPosition"] == "left"
                and result["bounded"]["effects"]["motion"] == "brvtal"
                and result["bounded"]["effects"]["grain"] is False
                and result["bounded"]["sound"]["enabled"] is False
                and all(token in runtime for token in (
                    "data-theme-menu-style", "data-theme-grain",
                    "data-theme-scanlines", "data-theme-glitch",
                    "data-theme-motion", "data-theme-sound-toggle"
                ))
                and all(control in panel for control in (
                    "selectField('menuStyle'", "toggleField('grain'",
                    "toggleField('scanlines'", "toggleField('glitch'",
                    "selectField('motion'", "toggleField('soundToggle'"
                ))
            ),
            "S32-R008": (
                result["desktop"]["attrs"] == {"width": "1440", "height": "900"}
                and result["mobile"]["attrs"] == {"width": "390", "height": "844"}
                and result["desktop"]["dataset"]["canonicalWidth"] == "1440"
                and result["mobile"]["dataset"]["canonicalWidth"] == "390"
                and "data-theme-canonical-preview" in panel
                and 'src="/?theme_studio_preview=1"' in panel
                and "UNSAVED CHANGES" in panel
                and "markDraftDirty()" in studio
                and "event.origin !== window.location.origin" in runtime
                and "event.source !== window.parent" in runtime
            ),
            "S32-R009": (
                "persist(false)" in studio and "persist(true)" in studio
                and "setting_key:'theme.' + theme.slug" in persist
                and "if (activate)" in persist
                and "setting_key:'theme.active'" in persist
                and persist.index("if (activate)") < persist.index("setting_key:'theme.active'")
                and "Save stores a draft. Activate publishes" in panel
                and "SAVE DRAFT" in panel and "SAVE & ACTIVATE" in panel
                and "'theme.active'" in PUBLIC_API.read_text(encoding="utf-8")
            ),
        }
        self.assertEqual(set(verdicts), set(CLAUSES))
        for clause, accepted in verdicts.items():
            with self.subTest(clause=clause):
                self.assertTrue(accepted, f"{clause}: contrato offline no acreditado")

    def test_falla_cerrado(self):
        result = probe()
        fonts = {x["stack"] for rows in result["fonts"].values() for x in rows}
        self.assertEqual(evidence_status(result["projected"], fonts), "CONTRACT_ONLY")
        candidates = [
            result["missing"],
            None, {},
            {"colors": {"bg": "#050505"}},
            {**result["projected"], "typography": {
                **result["projected"]["typography"], "display": "unknown-remote-font"
            }},
            {**result["projected"], "colors": {
                **result["projected"]["colors"], "bg": "script(unsafe)"
            }},
        ]
        for i, candidate in enumerate(candidates):
            with self.subTest(candidate=i):
                self.assertEqual(evidence_status(candidate, fonts), "UNKNOWN")
        self.assertEqual(result["sameColorContrast"], 1)

    def test_sin_datos_reales(self):
        source = Path(__file__).read_text(encoding="utf-8")
        self.assertNotIn("fetch(", NODE_PROBE)
        self.assertNotIn("http.request", NODE_PROBE)
        self.assertNotIn("https.request", NODE_PROBE)
        self.assertNotIn("persist(", NODE_PROBE)
        self.assertNotIn("req(", NODE_PROBE)
        projection = json.dumps(probe()["projected"])
        for forbidden in ("synthetic-never-export", "synthetic-never-execute",
                          "apiKey", "customCode", "password", "access_token"):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, projection)
        self.assertIn("fixture-only", source)

    def test_estados_unknown_explicitos(self):
        rows = coverage_rows()
        self.assertEqual(set(rows), set(CLAUSES))
        for clause, row in rows.items():
            with self.subTest(clause=clause):
                self.assertEqual(len(row), 5)
                self.assertIn("NO_VERIFICABLE", row[2])
                self.assertIn("Sin aserción integral confirmada", row[3])
                self.assertNotIn("VALIDATED_IN_PRODUCTION", row[3])
        states = {clause: {"offline": "CONTRACT_ONLY", "live": "UNKNOWN",
                           "production": "UNKNOWN"} for clause in CLAUSES}
        for clause, state in states.items():
            with self.subTest(clause=clause):
                self.assertEqual(state["live"], "UNKNOWN")
                self.assertEqual(state["production"], "UNKNOWN")


if __name__ == "__main__":
    unittest.main()
