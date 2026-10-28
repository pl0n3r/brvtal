#!/usr/bin/env python3
"""#1136: regresiones offline de límites de Theme Studio S32-R020..R024.

Contrato sobre fuentes y fixtures sintéticos. No acredita live, despliegue,
go-live ni ausencia global de otras funcionalidades.
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
API = ROOT / "api/public.php"
MATRIX = ROOT / "docs/matriz-cobertura.md"
CLAUSES = tuple(f"S32-R{n:03}" for n in range(20, 25))

# Instrumentación en memoria de las funciones reales del editor: sin DOM,
# backend, almacenamiento persistente, proveedores ni efectos de red.
NODE_PROBE = r"""
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const theme = JSON.parse(fs.readFileSync(0, 'utf8'));
const original = fs.readFileSync('discadmin/theme-studio-v2.js', 'utf8');
const end = original.lastIndexOf('})();');
if (end < 0 || original.slice(end + 5).trim()) {
  throw new Error('No se puede instrumentar Theme Studio con seguridad');
}
const instrumentation = [
  'globalThis.__probe = {',
  '  project: concept05PreviewTheme,',
  '  fontCatalog: FONT_CATALOG,',
  '  resetGroup: (input, section) => {',
  '    const next = JSON.parse(JSON.stringify(input));',
  '    applyConcept05Section(next, section);',
  '    return next;',
  '  },',
  '  presetDoesNotMutate: (candidate) => {',
  '    const before = JSON.stringify(state.theme);',
  '    preset(candidate);',
  '    return JSON.stringify(state.theme) === before;',
  '  }',
  '};',
].join('\n') + '\n';
const source = original.slice(0, end) + instrumentation + original.slice(end);
const sandbox = {
  state: {theme: JSON.parse(JSON.stringify(theme))},
  window: {addEventListener() {}},
  document: {addEventListener() {}},
};
vm.runInNewContext(source, sandbox, {timeout: 1500, filename: 'theme-studio-v2.js'});
const probe = sandbox.__probe;
const output = {
  preview: probe.project(theme),
  paletteReset: probe.resetGroup(theme, 'colors'),
  typographyReset: probe.resetGroup(theme, 'typography'),
  unsupportedPresets: Object.fromEntries(
    ['genesis', 'halloween', 'event-special', 'wix', ''].map(x => [x, probe.presetDoesNotMutate(x)])
  ),
  fontOptions: Object.fromEntries(Object.entries(probe.fontCatalog).map(
    ([key, values]) => [key, values.map(x => x.stack)]
  )),
};
process.stdout.write(JSON.stringify(output));
"""


def synthetic_theme() -> dict:
    return {
        "slug": "brvtal-core", "name": "BRVTAL CONCEPT 05",
        "branding": {"siteName": "BRVTAL", "tagline": "RAVE TILL GRAVE", "logo": "/fixture.svg"},
        "colors": {"bg": "#090909", "surface": "#0A0B0C", "text": "#F4F1E8",
                   "muted": "#8A8A82", "primary": "#E31B23", "accent": "#B6FF00",
                   "border": "#30302D"},
        "typography": {"display": '"Space Grotesk", Arial, sans-serif',
                       "body": '"Space Grotesk", Arial, sans-serif',
                       "mono": '"Space Mono", monospace',
                       "h1": "999px", "bodySize": "88px", "tracking": "10em"},
        "navigation": {"menuStyle": "wix", "logoPosition": "right", "sceneIndicator": True},
        "effects": {"motion": "arbitrary-script", "glitch": True},
        "sound": {"enabled": True},
        "customCode": 'alert("unsafe-fixture-marker")',
        "eventPreset": "genesis", "pages": [{"url": "/event/one-off"}],
        "layout": {"zIndex": 9999, "breakpoints": [123]},
        "analytics": {"apiKey": "fixture-only-should-not-project"},
    }


@lru_cache(maxsize=1)
def probe() -> dict:
    result = subprocess.run(
        ["node", "-e", NODE_PROBE], input=json.dumps(synthetic_theme()),
        cwd=ROOT, capture_output=True, text=True, timeout=20, check=False,
    )
    if result.returncode:
        raise AssertionError(f"Theme Studio offline Node probe failed: {result.stderr}")
    return json.loads(result.stdout)


def sections() -> tuple[str, str, str]:
    studio = STUDIO.read_text(encoding="utf-8")
    start = studio.index("function panel(theme)")
    end = studio.index("function currentTheme()", start)
    return studio, studio[start:end], RUNTIME.read_text(encoding="utf-8")


class BrechasBrvtal46Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        studio, panel, runtime = sections()
        result = probe()
        preview = result["preview"]
        reset = result["paletteReset"]
        typography = result["typographyReset"]
        base = synthetic_theme()
        public_api = API.read_text(encoding="utf-8")
        allowed = {
            "slug", "branding", "colors", "typography", "navigation", "effects", "sound",
        }
        verdicts = {
            "S32-R020": (
                'src="/?theme_studio_preview=1"' in panel
                and "data-theme-canonical-preview" in panel
                and "window.addEventListener('message', receivePreviewTheme)" in runtime
                and "event.origin !== window.location.origin" in runtime
                and "applyTheme(previewTheme)" in runtime
            ),
            "S32-R021": (
                set(preview) == allowed
                and "customCode" not in json.dumps(preview)
                and "unsafe-fixture-marker" not in json.dumps(preview)
                and "unset($themeOut['analytics'], $themeOut['customCode'])" in public_api
                and not re.search(r"\b(?:eval|new Function)\s*\(", studio + runtime)
            ),
            "S32-R022": (
                set(preview) == allowed
                and all(value not in json.dumps(preview) for value in
                        ('"pages"', '"layout"', '"breakpoints"', '"zIndex"'))
                and all(value not in panel.lower() for value in
                        ("custom js", "custom css", "wix", "elementor", "z-index"))
                and len(result["fontOptions"]["display"]) >= 2
            ),
            "S32-R023": (
                all(result["unsupportedPresets"].values())
                and "if (kind !== 'concept05') return;" in studio
                and "RESET VISUALS TO CONCEPT 05" in panel
            ),
            "S32-R024": (
                preview["colors"]["accent"] == base["colors"]["accent"]
                and preview["colors"]["primary"] == base["colors"]["primary"]
                and preview["typography"]["h1"] == "clamp(58px,9vw,132px)"
                and preview["typography"]["bodySize"] == "16px"
                and preview["navigation"]["menuStyle"] == "fullscreen"
                and preview["effects"]["motion"] == "brvtal"
                and reset["colors"]["bg"] == "#050505"
                and reset["slug"] == base["slug"]
                and reset["branding"] == base["branding"]
                and typography["typography"]["bodySize"] == "16px"
            ),
        }
        self.assertEqual(set(verdicts), set(CLAUSES))
        for clause, accepted in verdicts.items():
            with self.subTest(clause=clause):
                self.assertTrue(accepted, f"{clause}: contrato offline no acreditado")

    def test_falla_cerrado(self) -> None:
        actual = probe()
        preview = actual["preview"]
        for clause, forbidden in {
            "S32-R020": ("pages", "eventSite", "siteByEvent"),
            "S32-R021": ("customCode", "scripts", "html"),
            "S32-R022": ("layout", "breakpoints", "zIndex"),
            "S32-R023": ("eventPreset", "genesisPreset"),
        }.items():
            with self.subTest(clause=clause):
                self.assertFalse(set(forbidden) & set(preview), clause)
        for preset_name, unchanged in actual["unsupportedPresets"].items():
            with self.subTest(preset=preset_name):
                self.assertTrue(unchanged, f"Evento/preset inesperado: {preset_name}")
        self.assertNotEqual(preview["typography"]["h1"], synthetic_theme()["typography"]["h1"])
        self.assertEqual(preview["navigation"]["logoPosition"], "left")

    def test_sin_datos_reales(self) -> None:
        serialized = json.dumps(probe()["preview"])
        for forbidden in (
            "unsafe-fixture-marker", "apiKey", "password", "access_token",
            "authorization", "@example.", "Bearer ", "cookie",
        ):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, serialized)
        for unsafe in ("fetch(", "http.request", "https.request", "exec("):
            self.assertNotIn(unsafe, NODE_PROBE)

    def test_estados_unknown_explicitos(self) -> None:
        rows = [line for line in MATRIX.read_text(encoding="utf-8").splitlines()
                if any(line.startswith(f"| {clause} ·") for clause in CLAUSES)]
        self.assertEqual(len(rows), len(CLAUSES))
        for clause in CLAUSES:
            with self.subTest(clause=clause):
                cells = [cell.strip() for cell in next(
                    row for row in rows if row.startswith(f"| {clause} ·")
                ).strip("| ").split("|")]
                self.assertEqual(cells[2], "NO_VERIFICABLE")
                self.assertIn("Sin aserción integral confirmada", cells[3])
        statuses = {clause: {"offline": "CONTRACT_ONLY", "live": "UNKNOWN",
                             "production": "UNKNOWN"} for clause in CLAUSES}
        self.assertEqual(set(statuses), set(CLAUSES))
        for status in statuses.values():
            self.assertEqual(status["live"], "UNKNOWN")
            self.assertEqual(status["production"], "UNKNOWN")


if __name__ == "__main__":
    unittest.main()
