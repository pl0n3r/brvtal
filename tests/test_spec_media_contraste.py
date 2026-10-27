#!/usr/bin/env python3
"""Contraste offline de 32 filas S17-S18 de BRVTAL #1058."""
from __future__ import annotations

from collections import Counter
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
DOCUMENT = ROOT / "docs/contraste-spec_media.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
EXPECTED = ({f"S17-R{i:03d}" for i in range(1, 8)} - {"S17-R006"}) | {
    f"S18-R{i:03d}" for i in range(1, 27)
}
VALID = {"CUBIERTO", "DESCARTADO", "NO_VERIFICABLE", "SIN_COBERTURA"}

def read_document():
    rows = {}
    for line in DOCUMENT.read_text(encoding="utf-8").splitlines():
        if not re.match(r"^\\| S(?:17|18)-R\\d{3} \\|", line):
            continue
        cells = [part.strip() for part in line.strip("|").split("|")]
        if len(cells) != 6 or cells[0] in rows:
            raise AssertionError("Fila de contraste mal formada o repetida")
        rows[cells[0]] = cells
    return rows

def matrix_rows():
    rows = {}
    for line in MATRIX.read_text(encoding="utf-8").splitlines():
        found = re.match(r"^\\| (S(?:17|18)-R\\d{3}) \\u00b7 ", line)
        if found:
            cells = [part.strip() for part in line.strip("|").split("|")]
            if len(cells) != 5 or found.group(1) in rows:
                raise AssertionError("Fila de matriz mal formada o repetida")
            rows[found.group(1)] = cells
    return rows

class ContrasteTests(unittest.TestCase):
    def test_cada_fila_tiene_veredicto(self):
        rows = read_document()
        self.assertEqual(EXPECTED, set(rows))
        self.assertEqual(len(rows), 32)
        for key, (_, verdict, code, test, source, reason) in rows.items():
            with self.subTest(row=key):
                self.assertIn(verdict, VALID)
                self.assertGreater(len(reason), 50)
                self.assertNotEqual(source, "—")
                if verdict == "NO_VERIFICABLE":
                    self.assertIn("Falta", reason)
                elif verdict == "DESCARTADO":
                    self.assertRegex(reason, "Decisión|Exclusión")
                elif verdict == "CUBIERTO":
                    self.assertNotIn("—", (code, test))
        self.assertGreaterEqual(sum(r[1] == "CUBIERTO" for r in rows.values()), 15)

    def test_cubierto_enlaza_prueba_existente(self):
        rows = read_document()
        for key, (_, verdict, code, test, source, _) in rows.items():
            if verdict != "CUBIERTO":
                continue
            with self.subTest(row=key):
                for ref in (code, test):
                    match = re.fullmatch(r"\\[([^]]+)\\]\\(\\.\\./([^)]*)\\)", ref)
                    self.assertIsNotNone(match, ref)
                    assert match
                    self.assertEqual(match.group(1), match.group(2))
                    self.assertTrue((ROOT / match.group(2)).is_file(), ref)
                self.assertRegex(test, r"\\(\\.\\./tests/")
                self.assertRegex(source, r"\\[PR #\\d+\\]\\(https://github\\.com/pl0n3r/brvtal/pull/\\d+\\)")

    def test_faltante_tiene_issue(self):
        for key, (_, verdict, _, _, source, reason) in read_document().items():
            if verdict == "SIN_COBERTURA":
                with self.subTest(row=key):
                    self.assertRegex(source, r"https://github\\.com/pl0n3r/brvtal/issues/\\d+")
                    self.assertIn("brecha", reason.lower())
        # Sin brechas nuevas acreditadas, no se deben fabricar tickets.
        self.assertEqual(0, sum(row[1] == "SIN_COBERTURA" for row in read_document().values()))

    def test_matriz_actualizada(self):
        rows = read_document()
        current = matrix_rows()
        self.assertEqual(set(current) - {"S17-R006"}, EXPECTED)
        self.assertEqual(current["S17-R006"][2], "CUBIERTO")
        for key, row in rows.items():
            with self.subTest(row=key):
                self.assertEqual(current[key][2], row[1])
                self.assertIn("[contraste](contraste-spec_media.md)", current[key][3])
                if row[1] == "CUBIERTO":
                    self.assertIn("Código \`", current[key][3])
                    self.assertIn("prueba \`tests/", current[key][3])
                    pr = re.search(r"PR #(\\d+)", row[4])
                    self.assertIsNotNone(pr)
                    assert pr
                    self.assertIn(f"PR #{pr.group(1)} fusionado", current[key][3])
                    self.assertIn(f"/pull/{pr.group(1)}", current[key][4])
                if row[1] == "NO_VERIFICABLE":
                    self.assertIn("Falta", current[key][3])
        counts = Counter(row[1] for row in rows.values())
        self.assertGreaterEqual(counts["CUBIERTO"], 15)
        self.assertTrue(DOCUMENT.is_file())

if __name__ == "__main__":
    unittest.main()
