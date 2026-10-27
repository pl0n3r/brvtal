"""Contrato de cobertura verificable para la auditoría BRVTAL #1032."""
import re
import unittest
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SPEC = ROOT / "docs/BRVTAL-SPEC.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
STATES = {"CUBIERTO", "EN_CURSO", "SIN_COBERTURA", "DESCARTADO", "NO_VERIFICABLE"}


def read_rows():
    """Leer solo las cinco columnas de la tabla de cobertura, no el resumen."""
    text = MATRIX.read_text(encoding="utf-8")
    start = text.index("## Cobertura\n")
    end = text.index("\n## Resumen\n", start)
    raw = text[start:end].splitlines()
    data = []
    for line in raw:
        if not line.startswith("| "):
            continue
        columns = [field.strip() for field in line.strip().strip("|").split("|")]
        if columns[0] in {"ID / fuente", "---"} or all(
            re.fullmatch(r"[-: ]+", item) for item in columns
        ):
            continue
        data.append(columns)
    return text, data


class MatrizCoberturaTests(unittest.TestCase):
    def test_cubre_todas_las_fuentes(self):
        text, rows = read_rows()
        spec_lines = SPEC.read_text(encoding="utf-8").splitlines()
        headings = [
            number for number, line in enumerate(spec_lines, start=1)
            if re.match(r"^##\s+\d+(?:\.\d+)?(?:\.)?\s+", line)
        ]
        self.assertGreaterEqual(len(headings), 45)
        covered_lines = set()
        for source, *_ in rows:
            match = re.search(r"\bL(\d+)\]", source)
            if match:
                covered_lines.add(int(match.group(1)))
        for start, finish in zip(headings, headings[1:] + [len(spec_lines) + 1]):
            heading = spec_lines[start - 1]
            section_number = int(re.match(r"^##\s+(\d+)", heading).group(1))
            if section_number in {1, 5, 52}:  # Visión/narrativa sin AC atómicos.
                continue
            self.assertTrue(
                any(start < line < finish for line in covered_lines),
                f"Sección en línea {start} ausente en matriz",
            )
        section_number = 0
        for number, line in enumerate(spec_lines, start=1):
            heading = re.match(r"^##\s+(\d+)", line)
            if heading:
                section_number = int(heading.group(1))
            if section_number in {1, 5, 52}:
                continue
            if re.match(r"^\s*[-*]\s+\S", line):
                self.assertIn(number, covered_lines, f"Ítem sin fila: L{number}")
        for issue in (212, 975, 1029):
            self.assertIn(f"https://github.com/pl0n3r/brvtal/issues/{issue}", text)

    def test_sin_cobertura_tiene_issue(self):
        _, rows = read_rows()
        for source, requirement, state, evidence, issue in rows:
            if state == "SIN_COBERTURA":
                self.assertRegex(
                    issue, r"https://github\.com/pl0n3r/brvtal/issues/\d+",
                    f"Brecha sin issue: {source}: {requirement}",
                )
                self.assertNotIn("/issues/1032", issue)

    def test_formato_y_estados(self):
        text, rows = read_rows()
        self.assertIn(
            "| ID / fuente | Requisito resumido | Estado | Evidencia / falta verificar | Issue / PR |",
            text,
        )
        self.assertGreaterEqual(len(rows), 45)
        ids = []
        for columns in rows:
            self.assertEqual(len(columns), 5, columns[:1])
            source, requirement, state, evidence, issue = columns
            identifier = source.split(" · ")[0]
            self.assertRegex(identifier, r"^(?:S\d+[A-Z_0-9]*|H\d+|00)-[A-Z]?\w+$")
            ids.append(identifier)
            self.assertTrue(requirement and evidence and issue)
            self.assertIn(state, STATES, identifier)
            if state == "CUBIERTO":
                self.assertRegex(evidence, r"(?:tests/|PR #\d+)")
                self.assertRegex(evidence + " " + issue, r"PR #\d+")
            if state == "DESCARTADO":
                self.assertRegex(evidence.lower(), r"decisi|exclusi|retirad|fuera de alcance")
        self.assertEqual(len(ids), len(set(ids)), "IDs de requisito duplicados")

    def test_resumen_por_estado(self):
        text, rows = read_rows()
        counts = Counter(row[2] for row in rows)
        summary = text.split("\n## Resumen\n", 1)[1]
        for state in STATES:
            match = re.search(r"^\|\s*" + state + r"\s*\|\s*(\d+)\s*\|$", summary, re.M)
            self.assertIsNotNone(match, state)
            self.assertEqual(int(match.group(1)), counts[state], state)
        total = len(rows)
        self.assertIn(f"**Total:** {total} filas.", summary)
        expected = f"{100 * counts['CUBIERTO'] / total:.2f}% ({counts['CUBIERTO']}/{total})"
        self.assertIn(f"**Porcentaje CUBIERTO:** {expected}", summary)
        self.assertRegex(summary, r"\*\*Hojas canónicas nuevas creadas en esta auditoría:\*\* \d+")


if __name__ == "__main__":
    unittest.main()
