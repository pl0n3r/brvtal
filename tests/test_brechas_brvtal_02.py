#!/usr/bin/env python3
"""BRVTAL #1092: five synthetic, fail-closed public visual/navigation contracts.

LOCAL_PARTIAL proves only local source guards; it cannot certify visual taste,
real browser behaviour, deployed performance, accessibility, or owner approval.
The coverage matrix stays NO_VERIFICABLE until separately reconciled.
"""
from __future__ import annotations

import ast
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_PARTIAL"

IDS = ("S02-R005", "S03-L41", "S03-R001", "S03-R003", "S03-R004")
PHRASES = {
    "S02-R005": "The result must feel recognizably BRVTAL rather than like a generic SaaS dashboard or commercial template.",
    "S03-L41": "Lenis;",
    "S03-R001": "The public site is immersive and highly visual while remaining fast, usable, responsive and accessible.",
    "S03-R003": "fixed navigation;",
    "S03-R004": "fullscreen + menu;",
}
SOURCES = {
    "spec": "docs/BRVTAL-SPEC.md",
    "style": "css/style.css",
    "visual": "css/public-visual-identity.css",
    "input": "css/input-accessibility.css",
    "app": "js/app.js",
    "menu": "js/menu-accessibility.js",
    "scroll": "js/menu-scroll-lock.js",
}


def coverage_rows(raw: object) -> dict[str, tuple[str, ...]]:
    """Demand each original matrix row once, with all five intact fields."""
    if type(raw) is not str or not raw:
        raise ValueError("UNKNOWN: matriz ilegible")
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        for key in IDS:
            if not line.startswith(f"| {key} · "):
                continue
            if key in found:
                raise ValueError("UNKNOWN: fila duplicada " + key)
            cells = tuple(cell.strip() for cell in line.strip("|").split("|"))
            if (len(cells) != 5
                    or not cells[0].startswith(f"{key} · [S0")
                    or cells[1] != PHRASES[key]
                    or cells[2] != "NO_VERIFICABLE"
                    or not cells[3].startswith("Sin aserción contrastada")
                    or not cells[4]):
                raise ValueError("UNKNOWN: fila incompleta o promovida " + key)
            found[key] = cells
            break
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: faltan filas")
    return found


def local_sources() -> dict[str, str]:
    """Read only committed, public repository code and specifications."""
    return {key: (ROOT / path).read_text(encoding="utf-8")
            for key, path in SOURCES.items()}


def local_guards(files: object) -> dict[str, bool]:
    """Static, testable local preconditions; never substitute for browser E2E."""
    result = dict.fromkeys(IDS, False)
    if (type(files) is not dict or set(files) != set(SOURCES)
            or any(type(text) is not str or not text.strip()
                   for text in files.values())):
        return result
    spec, style, visual = files["spec"], files["style"], files["visual"]
    input_css, app, menu, scroll = (
        files["input"], files["app"], files["menu"], files["scroll"])
    if not all(phrase in spec for phrase in PHRASES.values()):
        return result

    result["S02-R005"] = all((
        "--brvtal-signal-red:#9e0d12" in visual,
        "brvtalHeroInterference" in visual,
        "repeating-linear-gradient" in visual,
        ".manifesto" in visual,
        ".mono{font-family:" in style,
    ))
    result["S03-L41"] = all((
        "new Lenis(" in app,
        "!reduce && motionReady && window.Lenis" in app,
        "lenisInstance.stop()" in scroll,
        "lenisInstance.start()" in scroll,
        "window.BRVTALScrollLock" in scroll,
    ))
    result["S03-R001"] = all((
        "@media (prefers-reduced-motion:reduce)" in style,
        "@media(prefers-reduced-motion:reduce)" in visual,
        "@media(max-width:900px)" in input_css,
        "min-height:44px" in input_css,
        "event.key === 'Escape'" in menu,
        "if (event.shiftKey && document.activeElement === first)" in menu,
    ))
    result["S03-R003"] = all((
        ".nav{position:fixed;top:0;left:0;right:0;" in style,
        "@media (max-width:900px)" in style,
        ".nav{" in input_css,
    ))
    result["S03-R004"] = all((
        ".menu-panel{position:fixed;inset:0;" in style,
        "menu.addEventListener('click'" in menu,
        "menu.setAttribute('aria-expanded', String(open));" in menu,
        "panel.setAttribute('aria-modal', 'true');" in menu,
        "panel.setAttribute('aria-hidden', String(!open));" in menu,
        "manager.lock('menu')" in scroll,
        "manager.unlock('menu')" in scroll,
    ))
    return result


def verdicts(matrix: object, files: object) -> dict[str, dict[str, str]]:
    """No local check can attest an external integration or production state."""
    unknown = lambda: {key: {"local": UNKNOWN, "integrated": UNKNOWN,
                             "ci": UNKNOWN, "production": UNKNOWN}
                       for key in IDS}
    try:
        rows = coverage_rows(matrix)
    except ValueError:
        return unknown()
    flags = local_guards(files)
    result = unknown()
    for key in IDS:
        if (key in rows and flags[key]
                and rows[key][1] == PHRASES[key]
                and rows[key][2] == "NO_VERIFICABLE"):
            result[key]["local"] = LOCAL
    return result


class BrechasBrvtal02Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        matrix = (ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8")
        rows, files = coverage_rows(matrix), local_sources()
        self.assertEqual(len(IDS), 5)
        self.assertEqual(set(rows), set(IDS))
        self.assertEqual(set(local_guards(files)), set(IDS))
        result = verdicts(matrix, files)
        for key in IDS:
            with self.subTest(clause=key):
                self.assertEqual(rows[key][1], PHRASES[key])
                self.assertEqual(rows[key][2], "NO_VERIFICABLE")
                self.assertTrue(local_guards(files)[key], "UNKNOWN guard: " + key)
                self.assertEqual(result[key]["local"], LOCAL)

    def test_falla_cerrado(self) -> None:
        matrix = (ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8")
        files = local_sources()
        first = next(line for line in matrix.splitlines()
                     if line.startswith("| S02-R005 · "))
        cases = (
            None, "", matrix.replace(first, "", 1), matrix + "\n" + first,
            matrix.replace(first, first.replace("NO_VERIFICABLE", "VERIFICADO", 1), 1),
            matrix.replace(first, first.replace("Sin aserción contrastada", "", 1), 1),
            matrix.replace(first, first.replace(" | — |", " |  |", 1), 1),
        )
        for broken in cases:
            with self.subTest(invalid=str(broken)[:55]):
                with self.assertRaises(ValueError):
                    coverage_rows(broken)
                self.assertTrue(all(
                    status["local"] == UNKNOWN
                    for status in verdicts(broken, files).values()))
        for invalid in (None, {}, {"spec": files["spec"]},
                        {**files, "extra": "unexpected"}):
            self.assertTrue(all(
                state["local"] == UNKNOWN
                for state in verdicts(matrix, invalid).values()))
        for key, file_name, token in (
            ("S02-R005", "visual", "--brvtal-signal-red:#9e0d12"),
            ("S03-L41", "app", "new Lenis("),
            ("S03-R001", "menu", "event.key === 'Escape'"),
            ("S03-R003", "style", ".nav{position:fixed;top:0;left:0;right:0;"),
            ("S03-R004", "menu", "panel.setAttribute('aria-modal', 'true');"),
        ):
            with self.subTest(missing_guard=key):
                changed = dict(files)
                changed[file_name] = changed[file_name].replace(token, "", 1)
                self.assertEqual(verdicts(matrix, changed)[key]["local"], UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        own = (ROOT / "tests/test_brechas_brvtal_02.py").read_text(
            encoding="utf-8")
        tree = ast.parse(own)
        imports = {
            alias.name for node in ast.walk(tree)
            if isinstance(node, ast.Import) for alias in node.names
        } | {
            node.module or "" for node in ast.walk(tree)
            if isinstance(node, ast.ImportFrom)
        }
        self.assertFalse(any(
            name == package or name.startswith(package + ".")
            for name in imports
            for package in ("requests", "httpx", "urllib", "socket",
                            "subprocess", "mysql", "pymysql")))
        self.assertTrue(all(
            not Path(path).is_absolute() and ".." not in Path(path).parts
            for path in SOURCES.values()))
        self.assertEqual(set(local_sources()), set(SOURCES))
        self.assertNotIn("BEGIN " + "PRIVATE KEY", own)

    def test_estados_unknown_explicitos(self) -> None:
        matrix = (ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8")
        files = local_sources()
        verdict = verdicts(matrix, files)
        for key in IDS:
            with self.subTest(clause=key):
                self.assertEqual(verdict[key]["local"], LOCAL)
                self.assertEqual(verdict[key]["integrated"], UNKNOWN)
                self.assertEqual(verdict[key]["ci"], UNKNOWN)
                self.assertEqual(verdict[key]["production"], UNKNOWN)
                self.assertEqual(verdicts(None, files)[key]["local"], UNKNOWN)
                self.assertEqual(verdicts(matrix, None)[key]["local"], UNKNOWN)


if __name__ == "__main__":
    unittest.main()
