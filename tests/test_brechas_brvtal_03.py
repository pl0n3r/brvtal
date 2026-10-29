#!/usr/bin/env python3
"""BRVTAL #1093: offline, fail-closed evidence for S03-R005..R009.

A source-level signal is not an end-to-end visual contract, a reviewed PR,
release, deployment or production attestation. All such states stay UNKNOWN.
"""
from __future__ import annotations

import ast
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/BRVTAL-SPEC.md"
APP = "js/app.js"
MOTION = "js/public-concept05-motion.js"
CSS = "css/style.css"
HERO = "css/public-concept05-hero.css"
E2E = "tests/e2e/public-menu-accessibility.spec.mjs"
SOURCES = (MATRIX, SPEC, APP, MOTION, CSS, HERO, E2E)
IDS = ("S03-R005", "S03-R006", "S03-R007", "S03-R008", "S03-R009")
CLAUSES = {
    "S03-R005": "preloader and page transitions;",
    "S03-R006": "GSAP / ScrollTrigger;",
    "S03-R007": "horizontal sections when narratively justified;",
    "S03-R008": "giant typography;",
    "S03-R009": "cursor and magnetic effects;",
}
UNKNOWN = "UNKNOWN"
PARTIAL = "EVIDENCIA_LOCAL_PARCIAL"
GAP = "BRECHA_DOCUMENTADA"


def read_sources() -> dict[str, str]:
    """Local tracked sources only; no HTTP, subprocess or private fixtures."""
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in SOURCES}


def matrix_rows(value: object) -> dict[str, tuple[str, ...]]:
    if type(value) is not str or not value:
        raise ValueError("UNKNOWN: matriz ausente")
    found: dict[str, tuple[str, ...]] = {}
    for line in value.splitlines():
        match = re.match(r"^\| (S03-R00[5-9]) · ", line)
        if not match:
            continue
        key = match.group(1)
        if key in found:
            raise ValueError("UNKNOWN: fila duplicada " + key)
        cells = tuple(x.strip() for x in line.strip("|").split("|"))
        if (len(cells) != 5 or not cells[0].startswith(key + " · [S03 L")
                or "docs/BRVTAL-SPEC.md#L" not in cells[0]
                or cells[1] != CLAUSES[key]
                or cells[2] != "NO_VERIFICABLE"
                or f"Sin aserción contrastada para «{CLAUSES[key]}»" not in cells[3]
                or "tests/e2e/public-menu-accessibility.spec.mjs" not in cells[3]
                or "falta enlazar prueba de ese inciso, archivo de código y PR fusionado" not in cells[3]
                or cells[4] != "—"):
            raise ValueError("UNKNOWN: fila alterada " + key)
        found[key] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: falta una de las cinco filas")
    return found


def spec_section(value: object) -> str | None:
    if type(value) is not str or value.count("## 3. Public frontend") != 1:
        return None
    after = value.split("## 3. Public frontend", 1)[1]
    return after.split("\n## 4.", 1)[0] if "\n## 4." in after else None


def local_signals(key: str, files: dict[str, str]) -> bool:
    """Contract checks against implementation, not browser or UX proof."""
    app, motion, css, hero = (files[p] for p in (APP, MOTION, CSS, HERO))
    if key == "S03-R005":
        # The preloader is observable in source, but a complete page-to-page
        # transition is NOT proven by an animated menu or loader.
        return all(s in app for s in (
            "const boot = () =>", "if (reduce || !motionReady) { loader.remove(); return; }",
            "onComplete:()=>loader.remove()", "gsap.to(panel,",
        ))
    if key == "S03-R006":
        return ("if (motionReady) gsap.registerPlugin(ScrollTrigger)" in app
                and "scrollTrigger:{" in app
                and "if (!window.gsap || motionDisabled()) return;" in motion
                and "gsap.registerPlugin(window.ScrollTrigger)" in motion
                and "prefers-reduced-motion: reduce" in motion)
    if key == "S03-R007":
        return all(s in app for s in (
            "if (!reduce && motionReady)", "const track = qs('.events-track')",
            "if(track)", "track.scrollWidth-innerWidth", "pin:true", "invalidateOnRefresh:true",
        )) and ".events-track{display:flex" in css
    if key == "S03-R008":
        return (bool(re.search(r"\.hero-title\s*\{\s*font-size:clamp\(100px,22vw,340px\)", css))
                and "font-size:clamp(80px,15vw,250px)" in css
                and "font-size:clamp(132px,14.6vw,220px)" in hero
                and "@media (prefers-reduced-motion:reduce)" in css)
    if key == "S03-R009":
        return all(s in app for s in (
            "const cursor=qs('.cursor')", "window.matchMedia('(pointer:fine)').matches",
            "qsa('.magnetic').forEach", "el.addEventListener('pointermove'",
            "el.addEventListener('pointerleave'", "gsap.to(el,{x:0,y:0",
        ))
    return False


def evidence(key: object, files: object) -> dict[str, str]:
    """Never promote source checks to a full user experience or CI success."""
    result = {"local": UNKNOWN, "full_experience": UNKNOWN,
              "ci": UNKNOWN, "release": UNKNOWN, "production": UNKNOWN}
    if type(key) is not str or key not in IDS or type(files) is not dict:
        return result
    if set(files) != set(SOURCES) or any(type(files[p]) is not str or not files[p]
                                          for p in SOURCES):
        return result
    try:
        matrix_rows(files[MATRIX])
    except ValueError:
        return result
    spec = spec_section(files[SPEC])
    if spec is None or CLAUSES[key] not in spec:
        return result
    if "test('public menu traps focus" not in files[E2E]:
        return result
    if not local_signals(key, files):
        return result
    # Page transitions are not shown merely by an initial preloader and menu.
    # Horizontal narrative justification and reduced-motion cursor E2E remain unproven.
    result["local"] = GAP if key == "S03-R005" else PARTIAL
    return result


def operational_attestation(_key: object, _claim: object = None) -> str:
    return UNKNOWN


class BrechasBrvtal03Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        files = read_sources()
        self.assertEqual(set(matrix_rows(files[MATRIX])), set(IDS))
        self.assertEqual(len(IDS), 5)
        for key in IDS:
            with self.subTest(row=key):
                expected = GAP if key == "S03-R005" else PARTIAL
                self.assertEqual(expected, evidence(key, files)["local"])
                self.assertEqual(UNKNOWN, evidence(key, files)["full_experience"])
                self.assertEqual(UNKNOWN, operational_attestation(key, files))

    def test_falla_cerrado(self) -> None:
        files = read_sources()
        matrix = files[MATRIX]
        for key in IDS:
            line = next(s for s in matrix.splitlines() if s.startswith("| " + key + " · "))
            for altered in (
                matrix.replace(line, "", 1), matrix + "\n" + line,
                matrix.replace(line, line.replace("NO_VERIFICABLE", "VERIFICADO"), 1),
            ):
                with self.subTest(row=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(altered)
                    self.assertEqual(UNKNOWN, evidence(key, {**files, MATRIX: altered})["local"])
            for path in (SPEC, APP, MOTION, CSS, HERO, E2E):
                self.assertEqual(UNKNOWN, evidence(key, {**files, path: ""})["local"])
        deleted = dict(files)
        deleted[APP] = deleted[APP].replace("gsap.to(el,{x:0,y:0", "gsap.to(el,{x:9,y:9", 1)
        self.assertEqual(UNKNOWN, evidence("S03-R009", deleted)["local"])
        deleted = dict(files)
        deleted[APP] = deleted[APP].replace("pin:true", "pin:false", 1)
        self.assertEqual(UNKNOWN, evidence("S03-R007", deleted)["local"])
        self.assertEqual(UNKNOWN, evidence("S03-R999", files)["local"])

    def test_sin_datos_reales(self) -> None:
        code = (ROOT / "tests/test_brechas_brvtal_03.py").read_text(encoding="utf-8")
        imports: set[str] = set()
        for node in ast.walk(ast.parse(code)):
            if isinstance(node, ast.Import):
                imports.update(alias.name.split(".")[0] for alias in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                imports.add(node.module.split(".")[0])
        self.assertLessEqual(imports, {"__future__", "ast", "pathlib", "re", "unittest"})
        self.assertEqual(tuple(SOURCES), (MATRIX, SPEC, APP, MOTION, CSS, HERO, E2E))
        self.assertNotIn("requests", imports)
        self.assertNotIn("subprocess", imports)
        self.assertNotIn("httpx", imports)

    def test_estados_unknown_explicitos(self) -> None:
        files = read_sources()
        for key in IDS:
            actual = evidence(key, files)
            for field in ("full_experience", "ci", "release", "production"):
                self.assertEqual(UNKNOWN, actual[field], (key, field))
            for asserted in (None, {}, True, {"ci": "success"},
                             {"deployed": True}, {"status": "VALIDATED_IN_PRODUCTION"}):
                self.assertEqual(UNKNOWN, operational_attestation(key, asserted))
        self.assertEqual(UNKNOWN, evidence(None, files)["local"])
        self.assertEqual(UNKNOWN, evidence("S03-R005", None)["local"])


if __name__ == "__main__":
    unittest.main()
