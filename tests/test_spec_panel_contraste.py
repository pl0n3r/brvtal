"""BRVTAL #1061: prueba fail-closed de 69 filas del contraste."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs/contraste-spec_panel.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
EXPECTED = ({f"S28-R{i:03d}" for i in range(1, 29)}
            | {f"S32-R{i:03d}" for i in range(1, 25)}
            | {f"S33-R{i:03d}" for i in range(1, 18)})
STATES = {"CUBIERTO", "NO_VERIFICABLE", "SIN_COBERTURA", "DESCARTADO"}

def rows(text):
    result = {}
    for line in text.splitlines():
        match = re.match(r"^\| (S(?:28|32|33)-R\d{3}) \|", line)
        if not match:
            continue
        cells = [v.strip() for v in line.strip("|").split("|")]
        if len(cells) != 6 or match.group(1) in result:
            raise ValueError("duplicada")
        result[match.group(1)] = cells
    return result

def validate(rows_by_id):
    for key, (_, status, code, test, source, reason) in rows_by_id.items():
        if status not in STATES or len(reason) < 65:
            raise ValueError("razón")
        if status == "CUBIERTO":
            if not (ROOT / code).is_file() or not (ROOT / test).is_file():
                raise ValueError("prueba inexistente")
            if not test.startswith("tests/") or not re.fullmatch(r"https://github\.com/pl0n3r/brvtal/pull/\d+", source):
                raise ValueError("PR")
        if status == "SIN_COBERTURA" and not re.fullmatch(r"https://github\.com/pl0n3r/brvtal/issues/\d+", source):
            raise ValueError("hoja")

def check_matrix(document, matrix):
    actual = {}
    for line in matrix.splitlines():
        match = re.match(r"^\| (S(?:28|32|33)-R\d{3}) ·", line)
        if match:
            cells = [v.strip() for v in line.strip("|").split("|")]
            if len(cells) != 5 or match.group(1) in actual:
                raise ValueError("matriz duplicada")
            actual[match.group(1)] = cells
    if set(document) != EXPECTED or set(actual) != EXPECTED:
        raise ValueError("censo")
    for key, row in actual.items():
        if row[2] != document[key][1]:
            raise ValueError("estado")
        if "[contraste](contraste-spec_panel.md)" not in row[3]:
            raise ValueError("enlace")
    section = matrix.split("## Cobertura\n", 1)[1].split("\n## Resumen\n", 1)[0]
    all_rows = [x for x in section.splitlines() if re.match(r"^\| (?:S\d+[A-Z_0-9]*|H\d+|00)-", x)]
    if len(all_rows) != 611:
        raise ValueError("historia")

class ContrasteTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.document = rows(DOC.read_text(encoding="utf-8"))
        cls.matrix = MATRIX.read_text(encoding="utf-8")

    def test_cada_fila_tiene_veredicto(self):
        self.assertEqual(set(self.document), EXPECTED)
        self.assertEqual(len(self.document), 69)
        self.assertTrue(all(row[1] in STATES for row in self.document.values()))

    def test_cubierto_enlaza_prueba_existente(self):
        validate(self.document)
        key = next(k for k, value in self.document.items() if value[1] == "CUBIERTO")
        fake = dict(self.document)
        row = list(fake[key])
        row[3] = "tests/does_not_exist_1061.php"
        fake[key] = row
        with self.assertRaises(ValueError):
            validate(fake)

    def test_faltante_tiene_issue(self):
        self.assertFalse(any(row[1] == "SIN_COBERTURA" for row in self.document.values()))
        fake = dict(self.document)
        key = next(iter(fake))
        row = list(fake[key])
        row[1], row[4] = "SIN_COBERTURA", "—"
        fake[key] = row
        with self.assertRaises(ValueError):
            validate(fake)

    def test_matriz_actualizada(self):
        check_matrix(self.document, self.matrix)
        with self.assertRaises(ValueError):
            check_matrix(self.document, self.matrix.replace("[contraste](contraste-spec_panel.md)", "", 1))
