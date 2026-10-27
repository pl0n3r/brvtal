#!/usr/bin/env python3
"""Contraste conservador de 191 filas SPEC; Factory acceptance #1063.

NO_VERIFICABLE no se convierte en cobertura por coincidencias textuales.
"""
from __future__ import annotations

from collections import Counter
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs/contraste-spec_resto.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
SECTIONS = {
    "S03": 14, "S06": 12, "S07": 20, "S12": 7, "S15": 7,
    "S16": 7, "S20": 22, "S21": 7, "S34": 8, "S35": 8,
    "S39": 10, "S39B": 16, "S40": 20, "S41": 6, "S44": 13,
    "S45": 5, "S46": 4, "S49": 5,
}
STATES = {"CUBIERTO", "NO_VERIFICABLE", "DESCARTADO", "SIN_COBERTURA"}
MERGED_PRS = {4, 12, 16, 19, 20, 22, 23, 605, 618, 622, 768, 850, 852}
ROW_RE = re.compile(r"^\| (S\d\dB?-[RL]\d{1,3})(?: \|| · )")


def parse_doc(text: str) -> dict[str, list[str]]:
    result = {}
    for line in text.splitlines():
        match = ROW_RE.match(line)
        if not match:
            continue
        cells = [v.strip() for v in line.strip("|").split("|")]
        if len(cells) != 6:
            raise ValueError("incorrect_document_columns")
        key = match.group(1)
        if key in result:
            raise ValueError("duplicate_document_row")
        result[key] = cells
    return result


def parse_matrix(text: str) -> tuple[dict[str, list[str]], int]:
    all_ids = set()
    selected = {}
    for line in text.splitlines():
        # El censo tiene tambien S51_1, S51_2 y filas historicas H*.
        if not re.match(r"^\\| (?:S\\d+[A-Z_0-9]*|H\\d+|00)-", line):
            continue
        key = line.split(" ·", 1)[0].split("|", 1)[1].strip()
        if key in all_ids:
            raise ValueError("duplicate_matrix_id")
        all_ids.add(key)
        cells = [v.strip() for v in line.strip("|").split("|")]
        if len(cells) != 5:
            raise ValueError("incorrect_matrix_columns")
        if key.split("-")[0] in SECTIONS:
            selected[key] = cells
    return selected, len(all_ids)


def validate_rows(doc: dict[str, list[str]]) -> None:
    by_section = Counter(key.split("-")[0] for key in doc)
    if dict(by_section) != SECTIONS:
        raise ValueError("incomplete_document_inventory")
    for key, cols in doc.items():
        _, status, code, test, source, reason = cols
        if status not in STATES or len(reason) < 80:
            raise ValueError("missing_verdict_or_reason")
        if status == "CUBIERTO":
            for value in (code, test):
                match = re.fullmatch(
                    r"\[\x60([^\x60]+)\x60\]\(\.\./([^)]+)\)", value
                )
                if not match or match.group(1) != match.group(2):
                    raise ValueError("invalid_covered_link")
                if not (ROOT / match.group(2)).is_file():
                    raise ValueError("missing_covered_file")
            if not test.startswith("[" + chr(96) + "tests/"):
                raise ValueError("covered_without_test")
            match = re.fullmatch(
                r"\[PR #(\d+) · [0-9a-f]{12}\]"
                r"\(https://github\.com/pl0n3r/brvtal/pull/\1\)", source
            )
            if not match or int(match.group(1)) not in MERGED_PRS:
                raise ValueError("unverified_merged_pr")
        elif status == "SIN_COBERTURA":
            if not re.search(
                r"https://github\.com/pl0n3r/brvtal/issues/\d+", source
            ):
                raise ValueError("missing_real_issue")
        elif status == "DESCARTADO":
            if not re.search(r"(?i)exclusi[oó]n.*introductorio", reason):
                raise ValueError("no_normative_discard_reason")
        elif not re.search(r"(?i)falta|no est[aá] acreditado|no demuestra", reason):
            raise ValueError("unknown_without_technical_limit")


def check_matrix(doc: dict[str, list[str]], matrix: dict[str, list[str]]) -> None:
    if set(matrix) != set(doc):
        raise ValueError("matrix_document_row_mismatch")
    for key, entry in doc.items():
        status = entry[1]
        cells = matrix[key]
        if cells[2] != status:
            raise ValueError("matrix_verdict_not_updated")
        if "[contraste](contraste-spec_resto.md)" not in cells[3]:
            raise ValueError("matrix_contrast_link_missing")
        if status == "CUBIERTO" and "tests/" not in cells[3]:
            raise ValueError("matrix_covered_test_missing")
        if status == "SIN_COBERTURA" and not re.search(
            r"https://github\.com/pl0n3r/brvtal/issues/\d+", cells[4]
        ):
            raise ValueError("matrix_missing_issue")


class ContrasteTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.doc = parse_doc(DOC.read_text(encoding="utf-8"))
        cls.matrix, cls.matrix_count = parse_matrix(MATRIX.read_text(encoding="utf-8"))

    def test_cada_fila_tiene_veredicto(self):
        self.assertEqual(len(self.doc), 191)
        validate_rows(self.doc)
        self.assertEqual(Counter(v[1] for v in self.doc.values()),
                         {"CUBIERTO": 16, "DESCARTADO": 4, "NO_VERIFICABLE": 171})
        example = next(iter(self.doc))
        fake = f"| {example} | CUBIERTO | — | — | — | razón |"
        with self.assertRaisesRegex(ValueError, "duplicate"):
            parse_doc(fake + "\n" + fake)
        altered = dict(self.doc)
        altered.pop(example)
        with self.assertRaisesRegex(ValueError, "incomplete"):
            validate_rows(altered)

    def test_cubierto_enlaza_prueba_existente(self):
        validate_rows(self.doc)
        example = next(k for k, row in self.doc.items() if row[1] == "CUBIERTO")
        altered = {k: list(v) for k, v in self.doc.items()}
        altered[example][3] = "[" + chr(96) + "tests/no-existing.py" + chr(96) + "](../tests/no-existing.py)"
        with self.assertRaisesRegex(ValueError, "missing_covered_file"):
            validate_rows(altered)
        altered[example] = list(self.doc[example])
        altered[example][4] = "[PR #9999 · abcdefabcdef](https://github.com/pl0n3r/brvtal/pull/9999)"
        with self.assertRaisesRegex(ValueError, "unverified_merged_pr"):
            validate_rows(altered)

    def test_faltante_tiene_issue(self):
        validate_rows(self.doc)
        self.assertFalse(any(row[1] == "SIN_COBERTURA" for row in self.doc.values()),
                         "No inventar defectos cuando solo falta evidencia")
        example = next(iter(self.doc))
        altered = {k: list(v) for k, v in self.doc.items()}
        altered[example][1] = "SIN_COBERTURA"
        altered[example][4] = "—"
        with self.assertRaisesRegex(ValueError, "missing_real_issue"):
            validate_rows(altered)

    def test_matriz_actualizada(self):
        self.assertEqual(self.matrix_count, 611)
        validate_rows(self.doc)
        check_matrix(self.doc, self.matrix)
        example = next(iter(self.doc))
        modified = {k: list(v) for k, v in self.matrix.items()}
        modified[example][3] = "Sin enlace de contraste"
        with self.assertRaisesRegex(ValueError, "matrix_contrast_link_missing"):
            check_matrix(self.doc, modified)


if __name__ == "__main__":
    unittest.main()
