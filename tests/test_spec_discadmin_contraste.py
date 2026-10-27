#!/usr/bin/env python3
"""Contraste reproducible de las 32 filas del Issue #1057 (sin red ni datos reales)."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOCUMENT = ROOT / "docs/contraste-spec_discadmin.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
EXPECTED = (
    {f"S23-R{n:03d}" for n in [2, 3, 4, 5, 6, 7, 8, 9, 10, 12]}
    | {f"S24-R{n:03d}" for n in [1, 2, 3, 5, 6, 7, 8, 9, 10, 11, 12, 13]}
    | {f"S25-R{n:03d}" for n in [1, 2, 4, 5, 6, 7, 8, 9, 10, 11]}
)
VALID = {"CUBIERTO", "DESCARTADO", "NO_APLICA", "SIN_COBERTURA"}


def read_rows() -> dict[str, list[str]]:
    rows: dict[str, list[str]] = {}
    for line in DOCUMENT.read_text(encoding="utf-8").splitlines():
        if not re.match(r"^\| S(?:23|24|25)-R\d{3} \|", line):
            continue
        cells = [cell.strip() for cell in line.strip("|").split("|")]
        if len(cells) != 6:
            raise AssertionError(f"Fila mal formada: {line[:100]}")
        if cells[0] in rows:
            raise AssertionError(f"Fila duplicada: {cells[0]}")
        rows[cells[0]] = cells
    return rows


class ContrasteTests(unittest.TestCase):
    def test_cada_fila_tiene_veredicto(self) -> None:
        rows = read_rows()
        self.assertEqual(set(rows), EXPECTED)
        for key, (_, status, code, proof, source, reason) in rows.items():
            with self.subTest(key=key):
                self.assertIn(status, VALID)
                self.assertTrue(reason and reason != "—")
                self.assertTrue(source and source != "—")
                if status == "CUBIERTO":
                    self.assertNotEqual(code, "—")
                    self.assertNotEqual(proof, "—")
                else:
                    self.assertNotIn("NO_VERIFICABLE", reason)

    def test_cubierto_enlaza_prueba_existente(self) -> None:
        rows = read_rows()
        for key, (_, status, code, proof, source, _) in rows.items():
            if status != "CUBIERTO":
                continue
            with self.subTest(key=key):
                for evidence in (code, proof):
                    match = re.fullmatch(r"\[([^]]+)\]\(\.\./([^)]*)\)", evidence)
                    self.assertIsNotNone(match, evidence)
                    assert match is not None
                    self.assertEqual(match.group(1), match.group(2))
                    path = ROOT / match.group(2)
                    self.assertTrue(path.is_file(), str(path))
                self.assertRegex(proof, r"\(\.\./tests/")
                self.assertRegex(source, r"https://github\.com/pl0n3r/brvtal/pull/\d+")

    def test_faltante_tiene_issue(self) -> None:
        for key, cells in read_rows().items():
            if cells[1] == "SIN_COBERTURA":
                with self.subTest(key=key):
                    self.assertRegex(cells[4], r"https://github\.com/pl0n3r/brvtal/issues/\d+")

    def test_matriz_actualizada(self) -> None:
        matrix_rows: dict[str, list[str]] = {}
        for line in MATRIX.read_text(encoding="utf-8").splitlines():
            match = re.match(r"^\| (S(?:23|24|25)-R\d{3}) \u00b7 ", line)
            if match:
                matrix_rows[match.group(1)] = [cell.strip() for cell in line.strip("|").split("|")]
        for key, doc_cells in read_rows().items():
            with self.subTest(key=key):
                self.assertIn(key, matrix_rows)
                self.assertEqual(matrix_rows[key][2], doc_cells[1])
                self.assertNotEqual(matrix_rows[key][2], "NO_VERIFICABLE")
                self.assertIn("contraste-spec_discadmin.md", matrix_rows[key][3])
                enlace = re.search(r"\[contraste\]\(([^)]+)\)", matrix_rows[key][3])
                self.assertIsNotNone(enlace, key)
                assert enlace is not None
                self.assertTrue((MATRIX.parent / enlace.group(1)).is_file(), key)
                if doc_cells[1] == "CUBIERTO":
                    self.assertIn(doc_cells[3].split("]")[0][1:], matrix_rows[key][3])
                if doc_cells[1] == "SIN_COBERTURA":
                    self.assertIn("/issues/", matrix_rows[key][4])


if __name__ == "__main__":
    unittest.main()
