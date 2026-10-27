#!/usr/bin/env python3
"""Auditoría verificable de 37 filas S26/S27/S42 (#1060); sin red ni PII."""
from __future__ import annotations
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOCUMENT = ROOT / "docs/contraste-spec_seguridad.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
EXPECTED = set(["S26-R001","S26-R003","S26-R004","S26-R005","S26-R006","S26-R007","S26-R008","S26-R009","S26-R010","S26-R011","S26-R012","S26-R013","S26-R014","S26-R015","S26-R016","S26-R017","S27-R001","S27-R002","S27-R004","S27-R005","S27-R006","S27-R007","S27-R008","S27-R009","S27-R010","S42-R001","S42-R002","S42-R003","S42-R005","S42-R006","S42-R007","S42-R008","S42-R009","S42-R010","S42-R011","S26-L427","S42-L662"])
VALID = {"CUBIERTO", "DESCARTADO", "SIN_COBERTURA", "NO_APLICA"}

def rows_documento():
    rows = {}
    for line in DOCUMENT.read_text(encoding="utf-8").splitlines():
        if not re.match(r"^\| S(?:26|27|42)-(?:R\d{3}|L\d+) \|", line):
            continue
        cols = [c.strip() for c in line.strip("|").split("|")]
        if len(cols) != 6 or cols[0] in rows:
            raise AssertionError(f"Duplicado/formato incorrecto: {line[:90]}")
        rows[cols[0]] = cols
    return rows

class ContrasteTests(unittest.TestCase):
    def test_cada_fila_tiene_veredicto(self):
        rows = rows_documento()
        self.assertEqual(set(rows), EXPECTED)
        for key, (_, estado, codigo, prueba, fuente, razon) in rows.items():
            with self.subTest(key=key):
                self.assertIn(estado, VALID)
                self.assertTrue(razon and fuente and fuente != "—")
                if estado == "CUBIERTO":
                    self.assertNotEqual(codigo, "—")
                    self.assertNotEqual(prueba, "—")
                    self.assertRegex(fuente, r"https://github\.com/pl0n3r/brvtal/pull/\d+")

    def test_cubierto_enlaza_prueba_existente(self):
        for key, row in rows_documento().items():
            if row[1] != "CUBIERTO":
                continue
            with self.subTest(key=key):
                for cell in row[2:4]:
                    match = re.fullmatch(r"\[([^]]+)\]\(\.\./([^)]*)\)", cell)
                    self.assertIsNotNone(match, cell)
                    assert match is not None
                    self.assertEqual(match.group(1), match.group(2))
                    self.assertTrue((ROOT / match.group(2)).is_file(), match.group(2))
                self.assertIn("(../tests/", row[3])

    def test_faltante_tiene_issue(self):
        for key, row in rows_documento().items():
            if row[1] == "SIN_COBERTURA":
                with self.subTest(key=key):
                    self.assertRegex(row[4], r"github\.com/pl0n3r/brvtal/issues/\d+")

    def test_matriz_actualizada(self):
        matrix = {}
        for line in MATRIX.read_text(encoding="utf-8").splitlines():
            found = re.match(r"^\| (S(?:26|27|42)-(?:R\d{3}|L\d+)) · ", line)
            if found:
                matrix[found.group(1)] = [c.strip() for c in line.strip("|").split("|")]
        for key, row in rows_documento().items():
            with self.subTest(key=key):
                self.assertIn(key, matrix)
                self.assertEqual(matrix[key][2], row[1])
                self.assertNotEqual(matrix[key][2], "NO_VERIFICABLE")
                link = re.search(r"\[contraste\]\(([^)]+)\)", matrix[key][3])
                self.assertIsNotNone(link, key)
                assert link is not None
                self.assertTrue((MATRIX.parent / link.group(1)).is_file())
                if row[1] == "CUBIERTO":
                    self.assertIn(row[3].split("]")[0][1:], matrix[key][3])
                elif row[1] == "SIN_COBERTURA":
                    self.assertIn("/issues/", matrix[key][4])

if __name__ == "__main__":
    unittest.main()
