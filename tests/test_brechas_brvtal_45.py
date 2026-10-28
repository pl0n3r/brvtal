#!/usr/bin/env python3
"""#1135: evidencia offline de las cinco cláusulas S32-R015..R019.

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
CLAUSES = tuple(f"S32-R{n:03}" for n in range(15, 20))

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



# Fuentes independientes del Theme Studio que anclan la autoridad de datos
# y el flujo real de preview; solo lectura del checkout versionado.
CONFIG = ROOT / "discadmin/theme-studio-configuration.js"
PREVIEW_UI = ROOT / "discadmin/public-preview.js"
PREVIEW_API = ROOT / "api/public-preview.php"
PREVIEW_PAGE = ROOT / "preview.php"
UNKNOWN = "UNKNOWN"
LOCAL = "PRUEBA_LOCAL_DECLARADA"


def matrix_rows(text: str) -> dict[str, tuple[str, ...]]:
    rows = {}
    for line in text.splitlines():
        match = re.match(r"^\| (S32-R\d{3}) · ", line)
        if not match or match.group(1) not in CLAUSES:
            continue
        key = match.group(1)
        if key in rows:
            raise ValueError("fila duplicada: " + key)
        cols = tuple(x.strip() for x in line.strip().strip("|").split("|"))
        if len(cols) != 5 or not cols[0].startswith(key + " · "):
            raise ValueError("fila invalida: " + key)
        rows[key] = cols
    return rows


def source_files() -> dict[str, str]:
    paths = {
        "studio": STUDIO, "runtime": RUNTIME, "api": API,
        "config": CONFIG, "preview_ui": PREVIEW_UI,
        "preview_api": PREVIEW_API, "preview_page": PREVIEW_PAGE,
    }
    return {key: path.read_text(encoding="utf-8") for key, path in paths.items()}


def states(row_id: str, rows: dict[str, tuple[str, ...]],
           sources: dict[str, str], result: dict | None) -> dict[str, str]:
    """Ausencia, ambigüedad, cobertura inventada o fuente faltante -> UNKNOWN."""
    verdict = {"code": UNKNOWN, "ci": UNKNOWN, "production": UNKNOWN}
    row = rows.get(row_id)
    required = {"studio", "runtime", "api", "config", "preview_ui",
                "preview_api", "preview_page"}
    if (row_id not in CLAUSES or row is None or row[2] != "NO_VERIFICABLE"
            or not required.issubset(sources)
            or not all(isinstance(sources[k], str) and sources[k] for k in required)
            or not isinstance(result, dict)
            or not isinstance(result.get("preview"), dict)):
        return verdict

    studio = sources["studio"]
    runtime = sources["runtime"]
    config = sources["config"]
    api = sources["api"]
    preview_ui = sources["preview_ui"]
    preview_api = sources["preview_api"]
    preview_page = sources["preview_page"]
    if "function panel(theme)" not in studio or "function currentTheme()" not in studio:
        return verdict
    panel = studio.split("function panel(theme)", 1)[1].split(
        "function currentTheme()", 1)[0]
    projection = result["preview"]
    allowed = {"slug", "branding", "colors", "typography",
               "navigation", "effects", "sound"}
    clean = set(projection) == allowed and not any(
        key in projection for key in ("seo", "analytics", "customCode",
                                    "skins", "layout", "pages",
                                    "eventPreset", "builder"))
    result_map = {
        "S32-R015": (
            clean and "if (seoTab) seoTab.hidden = true;" in config
            and "if (seoPane) seoPane.hidden = true;" in config
            and "MOVED TO SETTINGS" in config
            and "SEO / ANALYTICS" in config
            and 'data-theme-tab="seo"' not in panel
            and 'data-theme-pane="seo"' not in panel
            and "unset($themeOut['analytics'], $themeOut['customCode'])" in api
            and "Canonical SEO is rendered server-side" in runtime
        ),
        "S32-R016": (
            clean and 'src="/?theme_studio_preview=1"' in panel
            and "theme:concept05PreviewTheme(t)" in studio
            and "window.location.origin" in studio
            and "applyTheme(previewTheme)" in runtime
            and "applyTheme(previewTheme || settings.theme || null)" in runtime
            and "event.origin !== window.location.origin" in runtime
            and "event.source !== window.parent" in runtime
            and projection.get("navigation", {}).get("menuStyle") == "fullscreen"
            and projection.get("effects", {}).get("motion") == "brvtal"
        ),
        "S32-R017": (
            'data-theme-canonical-preview' in panel
            and "BRVTALPublicPreview" in preview_ui
            and "const endpoint = '/api/public-preview.php'" in preview_ui
            and "credentials:'same-origin'" in preview_ui
            and "brvtalPublicPreviewStore($snapshot)" in preview_api
            and "Cache-Control: no-store" in preview_api
            and "X-Robots-Tag: noindex, nofollow" in preview_api
            and "brvtalPublicEntityDocument(" in preview_page
            and "/api/public-preview.php" not in studio
        ),
        "S32-R018": (
            clean and not any(x in panel.lower() for x in (
                'data-theme-pane="builder"', 'data-theme-pane="skins"',
                "custom javascript editor", "custom css editor", "wix/elementor",
                "event-specific site", "unrestricted custom code"))
            and "if (kind !== 'concept05') return;" in studio
            and all(key not in projection for key in (
                "customCode", "pages", "builder", "eventPreset", "layout"))
        ),
        "S32-R019": (
            clean and "if (kind !== 'concept05') return;" in studio
            and "RESET VISUALS TO CONCEPT 05" in panel
            and 'data-theme-pane="skins"' not in panel
            and isinstance(result.get("unsupportedPresets"), dict)
            and len(result["unsupportedPresets"]) == 5
            and all(x is True for x in result["unsupportedPresets"].values())
        ),
    }
    if result_map[row_id]:
        verdict["code"] = LOCAL
    return verdict


class BrechasBrvtal45Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        rows = matrix_rows(MATRIX.read_text(encoding="utf-8"))
        sources = source_files()
        result = probe()  # función REAL, VM Node sintética y sin red
        self.assertEqual(set(rows), set(CLAUSES))
        for key in CLAUSES:
            with self.subTest(clause=key):
                self.assertEqual(states(key, rows, sources, result)["code"], LOCAL)

    def test_falla_cerrado(self) -> None:
        rows = matrix_rows(MATRIX.read_text(encoding="utf-8"))
        sources, result = source_files(), probe()
        sample = CLAUSES[0]
        self.assertEqual(states(sample, {}, sources, result)["code"], UNKNOWN)
        self.assertEqual(states(sample, rows, {}, result)["code"], UNKNOWN)
        self.assertEqual(states(sample, rows, sources, None)["code"], UNKNOWN)
        for name in sources:
            with self.subTest(missing=name):
                invalid = dict(sources)
                invalid[name] = ""
                self.assertEqual(states(sample, rows, invalid, result)["code"], UNKNOWN)
        altered = dict(result)
        altered["preview"] = {**result["preview"], "customCode": "synthetic"}
        for key in ("S32-R015", "S32-R016", "S32-R018", "S32-R019"):
            self.assertEqual(states(key, rows, sources, altered)["code"], UNKNOWN)
        raw = MATRIX.read_text(encoding="utf-8")
        duplicate = next(x for x in raw.splitlines() if x.startswith("| " + sample + " · "))
        with self.assertRaisesRegex(ValueError, "duplicada"):
            matrix_rows(raw + "\n" + duplicate)
        with self.assertRaisesRegex(ValueError, "invalida"):
            matrix_rows("| " + sample + " · invalid | no columns |")
        self.assertEqual(matrix_rows(""), {})

    def test_sin_datos_reales(self) -> None:
        self.assertEqual(synthetic_theme()["slug"], "brvtal-core")
        result = probe()
        self.assertEqual(result["preview"]["slug"], "brvtal-core")
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")
        self.assertNotRegex(
            own, r"(?m)^(?:import|from)\s+(?:requests|urllib|httpx|socket)\b")
        self.assertNotIn("fetch(", NODE_PROBE)
        self.assertNotIn("http.request(", NODE_PROBE)
        self.assertNotIn("https://" + "api.", own)

    def test_estados_unknown_explicitos(self) -> None:
        rows = matrix_rows(MATRIX.read_text(encoding="utf-8"))
        sources, result = source_files(), probe()
        for key in CLAUSES:
            with self.subTest(clause=key):
                self.assertEqual(rows[key][2], "NO_VERIFICABLE")
                evidence = states(key, rows, sources, result)
                self.assertEqual(evidence["code"], LOCAL)
                self.assertEqual(evidence["ci"], UNKNOWN)
                self.assertEqual(evidence["production"], UNKNOWN)
                forged = dict(rows)
                cols = list(rows[key])
                cols[2] = "CUBIERTO"
                forged[key] = tuple(cols)
                self.assertEqual(states(key, forged, sources, result)["code"], UNKNOWN)


if __name__ == "__main__":
    unittest.main()
