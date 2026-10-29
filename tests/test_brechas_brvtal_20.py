#!/usr/bin/env python3
"""#1110: synthetic/documentary media-gap coverage, never live proof."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
CONTRAST = ROOT / "docs/contraste-spec_media.md"
SPEC = ROOT / "docs/BRVTAL-SPEC.md"
JAVASCRIPT = ROOT / "js/public-media.js"
UNKNOWN = "UNKNOWN"
GAP = "DOCUMENTED_GAP"
SOURCE_ONLY = "LOCAL_SOURCE_ONLY"
CLAUSES = {
    "S17-R007": (217, "Images open in an accessible viewer; audio and video use native browser controls and all content remains limited to published API records.", "Falta afirmar audio.controls y video.controls en el render público"),
    "S18-R003": (225, "preserving uploaded originals;", "Falta crear un derivado de imagen real y demostrar la preservación de bytes originales"),
    "S18-R004": (226, "image dimensions and quality/dimension warnings;", "Falta comprobar square/card/hero y las etiquetas, dimensiones, estados y calidad de cada contexto"),
    "S18-R006": (228, "WebP generation when supported;", "Falta ejercer generación real GD/WebP con y sin capacidad disponible"),
    "S18-R009": (231, "reference-aware cleanup of originals/variants/sidecars.", "Falta acreditar borrado integral de original, variantes y sidecar con referencias reales"),
}


def parse_rows(text, contrast=False):
    """Read each canonical gap exactly once, rejecting indented duplicates."""
    result = {}
    for line in text.splitlines():
        line = line.lstrip()
        pattern = r"^\| (S(?:17|18)-R\d{3}) \|" if contrast else r"^\| (S(?:17|18)-R\d{3}) · "
        match = re.match(pattern, line)
        if not match or match.group(1) not in CLAUSES:
            continue
        key = match.group(1)
        columns = tuple(x.strip() for x in line.strip("|").split("|"))
        if key in result or len(columns) != (6 if contrast else 5):
            raise ValueError("UNKNOWN: malformed or duplicate media row")
        result[key] = columns
    if set(result) != set(CLAUSES):
        raise ValueError("UNKNOWN: missing media coverage row")
    return result


def native_controls(js):
    """Static JS source evidence only; does not simulate a browser."""
    match = re.search(
        r"function viewerMediaNode\(item\)\s*\{(.*?)\n\s*\}\n\s*function showViewerItem\(",
        js, re.S,
    )
    if not match:
        return False
    body = match.group(1)
    return all(x in body for x in (
        "url=fileUrl(item.file_path)", "if (!url) return",
        "if (type==='video')", "const video=create('video');",
        "video.controls=true;", "video.setAttribute('aria-label'",
        "if (type==='audio')", "const audio=create('audio');",
        "audio.controls=true;", "audio.setAttribute('aria-label'",
    )) and all(x in js for x in (
        "url.origin !== location.origin", "!url.pathname.startsWith('/uploads/')",
    ))


def inspect(key, matrix, contrast, spec, js):
    """Documentation cannot certify browser, CI, GD/WebP or production."""
    state = {"gap": UNKNOWN, "source": UNKNOWN, "browser": UNKNOWN,
             "ci": UNKNOWN, "production": UNKNOWN}
    if key not in CLAUSES or not isinstance(matrix, dict) or not isinstance(contrast, dict):
        return state
    if not isinstance(spec, str) or not isinstance(js, str):
        return state
    row, old = matrix.get(key), contrast.get(key)
    if not isinstance(row, tuple) or len(row) != 5 or not isinstance(old, tuple) or len(old) != 6:
        return state
    anchor, statement, missing = CLAUSES[key]
    if (not row[0].startswith(key + " · ") or "#L" + str(anchor) not in row[0]
            or row[1] != statement or row[2] != "NO_VERIFICABLE"
            or missing not in row[3] or "[contraste](contraste-spec_media.md)" not in row[3]
            or row[4] != "—" or old[0] != key or old[1] != "NO_VERIFICABLE"
            or old[2] != "—" or missing not in old[5] or statement not in spec):
        return state
    state["gap"] = GAP
    if key == "S17-R007" and native_controls(js):
        state["source"] = SOURCE_ONLY
    # The remaining gaps require executed binary generation, dimension
    # inspection and reference-aware cleanup to be verified; never fake these.
    return state


class BrechasBrvtal20Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.matrix = parse_rows(MATRIX.read_text(encoding="utf-8"))
        cls.contrast = parse_rows(CONTRAST.read_text(encoding="utf-8"), True)
        cls.spec = SPEC.read_text(encoding="utf-8")
        cls.js = JAVASCRIPT.read_text(encoding="utf-8")

    def test_contrato_principal(self):
        """AC-01: five rows, one positive source assertion, four gaps."""
        self.assertEqual(set(CLAUSES), set(self.matrix))
        self.assertEqual(set(CLAUSES), set(self.contrast))
        for key in CLAUSES:
            with self.subTest(key=key):
                observed = inspect(key, self.matrix, self.contrast, self.spec, self.js)
                self.assertEqual(GAP, observed["gap"])
                self.assertEqual(SOURCE_ONLY if key == "S17-R007" else UNKNOWN,
                                 observed["source"])

    def test_falla_cerrado(self):
        """AC-02: duplicates, missing, altered and promoted rows fail closed."""
        for path, is_contrast in ((MATRIX, False), (CONTRAST, True)):
            raw = path.read_text(encoding="utf-8")
            first = next(x for x in raw.splitlines() if x.startswith("| S17-R007 "))
            for changed in (raw + "\n" + first, raw + "\n   " + first,
                            raw.replace(first, "", 1)):
                with self.assertRaisesRegex(ValueError, UNKNOWN):
                    parse_rows(changed, is_contrast)
        for key in CLAUSES:
            with self.subTest(key=key):
                self.assertEqual(UNKNOWN, inspect(key, {}, self.contrast,
                                                  self.spec, self.js)["gap"])
                self.assertEqual(UNKNOWN, inspect(key, self.matrix, {},
                                                  self.spec, self.js)["gap"])
                promoted = dict(self.matrix)
                row = list(promoted[key]); row[2] = "CUBIERTO"
                promoted[key] = tuple(row)
                self.assertEqual(UNKNOWN, inspect(key, promoted, self.contrast,
                                                  self.spec, self.js)["gap"])
                statement = CLAUSES[key][1]
                self.assertEqual(UNKNOWN, inspect(key, self.matrix, self.contrast,
                                                  self.spec.replace(statement, ""), self.js)["gap"])
        self.assertEqual(UNKNOWN, inspect(
            "S17-R007", self.matrix, self.contrast, self.spec,
            self.js.replace("video.controls=true;", "video.controls=false;"))["source"])
        self.assertEqual(UNKNOWN, inspect("S18-R999", {}, {}, "", "")["gap"])

    def test_sin_datos_reales(self):
        """AC-03: read local repository only, with no secrets or network."""
        source = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(source, r"(?m)^(?:import|from)\s+(?:requests|httpx|urllib|socket|boto3|pymysql)\b")
        for f in (MATRIX, CONTRAST, SPEC, JAVASCRIPT):
            self.assertTrue(f.is_file())
            self.assertTrue(f.resolve().is_relative_to(ROOT.resolve()))
        self.assertTrue(all(self.matrix[key][2] == "NO_VERIFICABLE" for key in CLAUSES))

    def test_estados_unknown_explicitos(self):
        """AC-04: no inferred CI, browser execution, production or media generation."""
        for key in CLAUSES:
            with self.subTest(key=key):
                observed = inspect(key, self.matrix, self.contrast, self.spec, self.js)
                for category in ("browser", "ci", "production"):
                    self.assertEqual(UNKNOWN, observed[category])
                if key != "S17-R007":
                    self.assertEqual(UNKNOWN, observed["source"])
                self.assertEqual(UNKNOWN, inspect(key, {}, {}, "", "")["gap"])


if __name__ == "__main__":
    unittest.main()
