#!/usr/bin/env python3
"""Factory acceptance #1062: 50 documented S51 rows, no production claims."""
from __future__ import annotations

import re
import unittest
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs/contraste-spec_editor.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
EXPECTED = (
    {f"S51-R{i:03d}" for i in range(1, 22)}
    | {f"S51_1-R{i:03d}" for i in range(1, 15)}
    | {f"S51_2-R{i:03d}" for i in range(1, 16)}
)
STATES = {"CUBIERTO", "NO_VERIFICABLE", "DESCARTADO", "SIN_COBERTURA"}
# Verified merged on GitHub; existence of an arbitrary PR is insufficient.
MERGED = {572, 574}


def parse_doc(text: str) -> dict[str, list[str]]:
    rows: dict[str, list[str]] = {}
    for line in text.splitlines():
        m = re.match(r"^\| (S51(?:_1|_2)?-R\d{3}) \|", line)
        if not m:
            continue
        cols = [x.strip() for x in line.strip("|").split("|")]
        if len(cols) != 6:
            raise ValueError("Fila documental sin seis columnas")
        if m.group(1) in rows:
            raise ValueError("ID documental duplicado: " + m.group(1))
        rows[m.group(1)] = cols
    return rows


def inspect_doc(rows: dict[str, list[str]]) -> None:
    for key, (_, status, code, proof, source, reason) in rows.items():
        if status not in STATES or not reason or reason == "—":
            raise ValueError("Veredicto vacío o inválido: " + key)
        if status == "CUBIERTO":
            for field in (code, proof):
                m = re.fullmatch(r"\[\x60([^\x60]+)\x60\]\(\.\./([^)]*)\)", field)
                if m is None or m.group(1) != m.group(2):
                    raise ValueError("Enlace de evidencia roto: " + key)
                if not (ROOT / m.group(2)).is_file():
                    raise ValueError("Evidencia inexistente: " + key)
            if not re.search(r"\(\.\./tests/", proof):
                raise ValueError("Prueba sin ruta de tests: " + key)
            m = re.fullmatch(
                r"\[PR #(\d+) · ([0-9a-f]{12})\]"
                r"\(https://github\.com/pl0n3r/brvtal/pull/\1\)", source,
            )
            if m is None or int(m.group(1)) not in MERGED:
                raise ValueError("PR fusionado no verificado: " + key)
        elif status == "SIN_COBERTURA":
            if not re.search(r"https://github\.com/pl0n3r/brvtal/issues/\d+", source):
                raise ValueError("SIN_COBERTURA sin hoja real: " + key)
        elif status == "DESCARTADO":
            if not re.search(r"(?i)exclusi|decisi|retirad|fuera de alcance", reason):
                raise ValueError("DESCARTADO sin razón: " + key)
        elif len(reason) < 85:
            raise ValueError("NO_VERIFICABLE sin límite concreto: " + key)


def parse_matrix(text: str) -> dict[str, list[str]]:
    rows: dict[str, list[str]] = {}
    for line in text.splitlines():
        m = re.match(r"^\| (S51(?:_1|_2)?-R\d{3}) ·", line)
        if not m:
            continue
        cols = [x.strip() for x in line.strip("|").split("|")]
        if len(cols) != 5 or m.group(1) in rows:
            raise ValueError("Matriz duplicada o mal formada")
        rows[m.group(1)] = cols
    return rows


def compare_matrix(doc: dict[str, list[str]], matrix: dict[str, list[str]]) -> None:
    if set(doc) != EXPECTED or set(matrix) != EXPECTED:
        raise ValueError("No se contrastaron las 50 filas originales")
    for key, (_, status, _, proof, _, _) in doc.items():
        cells = matrix[key]
        if cells[2] != status:
            raise ValueError("Matriz no actualizada: " + key)
        if "[contraste](contraste-spec_editor.md)" not in cells[3]:
            raise ValueError("Contraste no enlazado: " + key)
        if status == "CUBIERTO":
            test_path = proof.split(chr(96))[1]
            if test_path not in cells[3]:
                raise ValueError("Prueba inconsistente: " + key)
            if not re.search(r"https://github\.com/pl0n3r/brvtal/pull/\d+", cells[4]):
                raise ValueError("PR no enlazado: " + key)
        if status == "SIN_COBERTURA" and not re.search(
            r"https://github\.com/pl0n3r/brvtal/issues/\d+", cells[4]
        ):
            raise ValueError("Issue no enlazado: " + key)


class ContrasteTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.doc = parse_doc(DOC.read_text(encoding="utf-8"))
        cls.matrix_text = MATRIX.read_text(encoding="utf-8")

    def test_cada_fila_tiene_veredicto(self) -> None:
        self.assertEqual(len(self.doc), 50)
        self.assertEqual(set(self.doc), EXPECTED)
        self.assertTrue(all(v[1] in STATES and v[5] for v in self.doc.values()))
        key = next(iter(self.doc))
        fake = f"| {key} | CUBIERTO | — | — | — | razón |\n"
        with self.assertRaisesRegex(ValueError, "duplicado"):
            parse_doc(fake + fake)

    def test_cubierto_enlaza_prueba_existente(self) -> None:
        inspect_doc(self.doc)
        key = next(k for k, v in self.doc.items() if v[1] == "CUBIERTO")
        altered = dict(self.doc)
        row = list(altered[key])
        row[3] = "[prueba inexistente](../tests/inexistente.py)"
        altered[key] = row
        with self.assertRaisesRegex(ValueError, "Enlace"):
            inspect_doc(altered)

    def test_faltante_tiene_issue(self) -> None:
        # Ausencia positiva no demostrada: no fabricar SIN_COBERTURA.
        self.assertFalse(any(v[1] == "SIN_COBERTURA" for v in self.doc.values()))
        key = next(iter(self.doc))
        altered = dict(self.doc)
        row = list(altered[key])
        row[1] = "SIN_COBERTURA"
        row[4] = "—"
        altered[key] = row
        with self.assertRaisesRegex(ValueError, "hoja real"):
            inspect_doc(altered)

    def test_matriz_actualizada(self) -> None:
        actual = parse_matrix(self.matrix_text)
        compare_matrix(self.doc, actual)
        key = next(k for k, v in self.doc.items() if v[1] == "CUBIERTO")
        corrupted = dict(actual)
        fake = list(corrupted[key])
        fake[2] = "NO_VERIFICABLE"
        corrupted[key] = fake
        with self.assertRaisesRegex(ValueError, "no actualizada"):
            compare_matrix(self.doc, corrupted)

        # Conserva exactamente el censo original de 611 IDs y sus resúmenes.
        part = self.matrix_text.split("## Cobertura\n", 1)[1].split("\n## Resumen\n", 1)[0]
        all_rows = [
            x for x in part.splitlines()
            if re.match(r"^\| (?:S\d+[A-Z_0-9]*|H\d+|00)-", x)
        ]
        self.assertEqual(len(all_rows), 611)
        counts = Counter(row.split("|")[3].strip() for row in all_rows)
        summary = self.matrix_text.split("\n## Resumen\n", 1)[1]
        for state, count in counts.items():
            self.assertIn(f"| {state} | {count} |", summary)
        self.assertIn("**Total:** 611 filas.", summary)


if __name__ == "__main__":
    unittest.main()
