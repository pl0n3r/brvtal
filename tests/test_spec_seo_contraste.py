#!/usr/bin/env python3
"""S22: auditoría reproducible de veredictos SEO sin consultar red o datos reales."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs/contraste-spec_seo.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
EXPECTED = {f"S22-R{i:03}" for i in range(1, 55)}
VERDICTS = {"CUBIERTO", "SIN_COBERTURA", "DESCARTADO"}
MERGED_PRS = {385, 469, 601, 620, 621, 1002}
GAP_ISSUES = {1068}


def report_rows() -> dict[str, list[str]]:
    """Parsear una única tabla de seis columnas, con IDs originales S22."""
    data: dict[str, list[str]] = {}
    for line in DOC.read_text(encoding="utf-8").splitlines():
        if not re.match(r"^\| S22-R\d{3} \|", line):
            continue
        cells = [c.strip() for c in line.strip("|").split("|")]
        if len(cells) != 6:
            raise AssertionError(f"Fila S22 mal formada: {line[:80]}")
        if cells[0] in data:
            raise AssertionError(f"ID S22 duplicado: {cells[0]}")
        data[cells[0]] = cells
    return data


def matrix_rows() -> dict[str, list[str]]:
    data: dict[str, list[str]] = {}
    text = MATRIX.read_text(encoding="utf-8")
    start = text.index("## Cobertura\n")
    end = text.index("\n## Resumen\n", start)
    for line in text[start:end].splitlines():
        match = re.match(r"^\| (S22-R\d{3}) · ", line)
        if not match:
            continue
        key = match.group(1)
        if key in data:
            raise AssertionError(f"Duplicado en matriz: {key}")
        data[key] = [c.strip() for c in line.strip("|").split("|")]
    return data


class ContrasteTests(unittest.TestCase):
    def test_cada_fila_tiene_veredicto(self) -> None:
        rows = report_rows()
        self.assertEqual(set(rows), EXPECTED)
        for key, (_, state, code, test, source, explanation) in rows.items():
            with self.subTest(key=key):
                self.assertIn(state, VERDICTS)
                self.assertTrue(source and source != "—")
                self.assertGreater(len(explanation), 40)
                self.assertNotIn("NO_VERIFICABLE", explanation)
                if state == "CUBIERTO":
                    self.assertNotEqual(code, "—")
                    self.assertNotEqual(test, "—")
                elif state == "SIN_COBERTURA":
                    self.assertIn("BRECHA COMPROBADA", explanation)
                else:
                    self.assertIn("Decisión de auditoría", explanation)

    def test_cubierto_enlaza_prueba_existente(self) -> None:
        for key, (_, state, code, proof, source, _) in report_rows().items():
            if state != "CUBIERTO":
                continue
            with self.subTest(key=key):
                for cell in (code, proof):
                    found = re.fullmatch(r"\[([^]]+)\]\(\.\./([^)]*)\)", cell)
                    self.assertIsNotNone(found, cell)
                    assert found is not None
                    self.assertEqual(found.group(1), found.group(2))
                    self.assertTrue((ROOT / found.group(2)).is_file(), found.group(2))
                self.assertRegex(proof, r"\(\.\./tests/")
                pr = re.search(r"https://github\.com/pl0n3r/brvtal/pull/(\d+)", source)
                self.assertIsNotNone(pr, (key, source))
                assert pr is not None
                self.assertIn(int(pr.group(1)), MERGED_PRS, key)

    def test_faltante_tiene_issue(self) -> None:
        rows = report_rows()
        self.assertEqual(rows["S22-R043"][1], "SIN_COBERTURA")
        self.assertIn("https://github.com/pl0n3r/brvtal/issues/1068", rows["S22-R043"][4])
        for key, (_, state, _, _, source, _) in rows.items():
            if state == "SIN_COBERTURA":
                ref = re.search(r"https://github\.com/pl0n3r/brvtal/issues/(\d+)", source)
                self.assertIsNotNone(ref, key)
                assert ref is not None
                self.assertIn(int(ref.group(1)), GAP_ISSUES, key)

    def test_matriz_actualizada(self) -> None:
        # Intencionadamente fail-closed hasta reclamar y actualizar matriz.
        matrix = matrix_rows()
        report = report_rows()
        self.assertEqual(set(matrix), EXPECTED)
        for key, cells in report.items():
            with self.subTest(key=key):
                self.assertEqual(matrix[key][2], cells[1], key)
                self.assertNotIn("NO_VERIFICABLE", matrix[key][2])
                if key not in {"S22-R001", "S22-R004"}:
                    self.assertIn("contraste-spec_seo.md", matrix[key][3])
                if cells[1] == "SIN_COBERTURA":
                    self.assertIn("/issues/1068", matrix[key][4])


if __name__ == "__main__":
    unittest.main()
