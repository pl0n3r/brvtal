"""Contrato de auditoría verificable BRVTAL #1038: evidencia exacta y estados fail-closed."""
import re
import unittest
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SPEC = ROOT / "docs/BRVTAL-SPEC.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
STATES = {"CUBIERTO", "EN_CURSO", "SIN_COBERTURA", "DESCARTADO", "NO_VERIFICABLE"}

# Referencias revisadas contra GitHub en esta pasada, no inferidas de un epic.
# Cuando haya nuevo trabajo ejecutable debe agregarse aquí tras contrastar su
# contrato factory-plan-task y estado vivo o el PR realmente OPEN.
OPEN_EXECUTABLE_ISSUES = {1034}
OPEN_PULL_REQUESTS = {1037}
# Cada PR referido como prueba de CUBIERTO fue consultado en la lista de PRs
# fusionados o verificado por su referencia merged exacta en la auditoría.
MERGED_EVIDENCE_PRS = {
    1, 4, 10, 12, 16, 19, 20, 21, 22, 23, 36, 40, 44, 402,
    549, 605, 618, 621, 622, 768, 850, 852, 877, 947, 1030, 1031,
    18, 68, 385, 916, 917, 918, 340,
    334, 897, 902, 903, 910,
}


def read_rows():
    """Leer cinco columnas del bloque de cobertura, nunca filas de resumen."""
    text = MATRIX.read_text(encoding="utf-8")
    start = text.index("## Cobertura\n")
    end = text.index("\n## Resumen\n", start)
    data = []
    for line in text[start:end].splitlines():
        if not line.startswith("| "):
            continue
        cols = [field.strip() for field in line.strip().strip("|").split("|")]
        if cols[0] == "ID / fuente" or all(
            re.fullmatch(r"[-: ]+", col) for col in cols
        ):
            continue
        data.append(cols)
    return text, data


def issue_or_pr_number(reference):
    issues = set(map(int, re.findall(
        r"https://github\.com/pl0n3r/brvtal/issues/(\d+)", reference
    )))
    prs = set(map(int, re.findall(
        r"https://github\.com/pl0n3r/brvtal/pull/(\d+)", reference
    )))
    return issues, prs


def valid_in_progress(reference):
    """Ni roadmap/epic ni PR fusionado confieren EN_CURSO."""
    issues, prs = issue_or_pr_number(reference)
    return bool((issues & OPEN_EXECUTABLE_ISSUES) or
                (prs & OPEN_PULL_REQUESTS))


class MatrizCoberturaTests(unittest.TestCase):
    def test_cubre_todo_el_alcance(self):
        text, rows = read_rows()
        self.assertEqual(len(rows), 611)
        lines = SPEC.read_text(encoding="utf-8").splitlines()
        headings = [
            number for number, line in enumerate(lines, start=1)
            if re.match(r"^##\s+\d+(?:\.\d+)?(?:\.)?\s+", line)
        ]
        self.assertGreaterEqual(len(headings), 45)
        covered_lines = {
            int(match.group(1))
            for source, *_ in rows
            if (match := re.search(r"\bL(\d+)\]", source))
        }
        for start, finish in zip(headings, headings[1:] + [len(lines) + 1]):
            heading = lines[start - 1]
            section = int(re.match(r"^##\s+(\d+)", heading).group(1))
            if section in {1, 5, 52}:
                continue
            self.assertTrue(
                any(start < i < finish for i in covered_lines),
                f"Falta sección de SPEC en L{start}",
            )
        section = 0
        for index, line in enumerate(lines, start=1):
            heading = re.match(r"^##\s+(\d+)", line)
            if heading:
                section = int(heading.group(1))
            if section in {1, 5, 52}:
                continue
            if re.match(r"^\s*[-*]\s+\S", line):
                self.assertIn(index, covered_lines, f"Falta requisito L{index}")
        for n in (212, 975, 1029):
            self.assertIn(f"https://github.com/pl0n3r/brvtal/issues/{n}", text)
        self.assertIn("secciones 2–35", text)

    def test_sin_cobertura_tiene_issue(self):
        _, rows = read_rows()
        for source, requirement, state, _, reference in rows:
            if state != "SIN_COBERTURA":
                continue
            issues, _ = issue_or_pr_number(reference)
            self.assertTrue(issues, f"Sin hoja: {source}, {requirement}")
            # Una nueva brecha no se inventa desde un gap documental: declarar
            # una hoja canónica solo tras incorporarla explícitamente a la prueba.
            self.assertNotIn(1038, issues)

    def test_formato_y_estados(self):
        text, rows = read_rows()
        self.assertIn(
            "| ID / fuente | Requisito resumido | Estado | Evidencia / falta verificar | Issue / PR |",
            text,
        )
        ids = set()
        covered = 0
        for cols in rows:
            self.assertEqual(len(cols), 5, cols[:1])
            source, requirement, state, evidence, reference = cols
            identifier = source.split(" · ")[0]
            self.assertRegex(identifier, r"^(?:S\d+[A-Z_0-9]*|H\d+|00)-[A-Z]?\w+$")
            self.assertNotIn(identifier, ids)
            ids.add(identifier)
            self.assertTrue(requirement and evidence and reference)
            self.assertIn(state, STATES)
            if state == "CUBIERTO":
                covered += 1
                match = re.fullmatch(
                    r"Código \x60([^\x60]+)\x60; prueba \x60(tests/[^\x60]+)\x60"
                    r".+; PR #(\d+) fusionado .+", evidence
                )
                self.assertIsNotNone(match, identifier)
                source_path, test_path, pr_num = match.groups()
                for item in (source_path, test_path):
                    self.assertTrue((ROOT / item).is_file(), (identifier, item))
                self.assertIn(int(pr_num), MERGED_EVIDENCE_PRS, identifier)
                _, prs = issue_or_pr_number(reference)
                self.assertIn(int(pr_num), prs, identifier)
            elif state == "EN_CURSO":
                self.assertTrue(valid_in_progress(reference), identifier)
            elif state == "SIN_COBERTURA":
                self.assertTrue(issue_or_pr_number(reference)[0], identifier)
            elif state == "DESCARTADO":
                self.assertRegex(
                    evidence.lower(), r"decisi|exclusi|retirad|fuera de alcance"
                )
            else:
                self.assertGreater(len(evidence), 45, identifier)
        self.assertGreaterEqual(covered, 30)
        self.assertNotIn("Issue #975 OPEN, PR #1031 DRAFT", text)

    def test_en_curso_no_acepta_epics_ni_pr_fusionados(self):
        for reference in (
            "https://github.com/pl0n3r/brvtal/issues/533",
            "https://github.com/pl0n3r/brvtal/issues/212",
            "https://github.com/pl0n3r/brvtal/issues/975",
            "https://github.com/pl0n3r/brvtal/pull/1031",
            "planning #533",
            "",
        ):
            with self.subTest(reference=reference):
                self.assertFalse(valid_in_progress(reference))
        self.assertTrue(valid_in_progress(
            "https://github.com/pl0n3r/brvtal/issues/1034"
        ))
        self.assertTrue(valid_in_progress(
            "https://github.com/pl0n3r/brvtal/pull/1037"
        ))

    def test_resumen_final(self):
        text, rows = read_rows()
        count = Counter(r[2] for r in rows)
        summary = text.split("\n## Resumen\n", 1)[1]
        for state in STATES:
            match = re.search(
                r"^\|\s*" + state + r"\s*\|\s*(\d+)\s*\|$",
                summary, re.M,
            )
            self.assertIsNotNone(match, state)
            self.assertEqual(int(match.group(1)), count[state])
        total = len(rows)
        self.assertIn(f"**Total:** {total} filas.", summary)
        pct = f"{100 * count['CUBIERTO'] / total:.2f}% ({count['CUBIERTO']}/{total})"
        self.assertIn(f"**Porcentaje CUBIERTO:** {pct}", summary)
        self.assertRegex(
            summary, r"\*\*Hojas canónicas nuevas creadas en esta auditoría:\*\* \d+"
        )


if __name__ == "__main__":
    unittest.main()
