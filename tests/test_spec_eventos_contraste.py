#!/usr/bin/env python3
"""Contrato reproducible de contraste S09–S11; no consulta red ni datos reales."""
from __future__ import annotations

import json
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs/contraste-spec_eventos.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
EXPECTED = {f"S09-R{i:03}" for i in range(1, 10)} | {f"S10-R{i:03}" for i in range(1, 27)} | {f"S11-R{i:03}" for i in range(1, 10)}
VALID = {"CUBIERTO", "DESCARTADO", "SIN_COBERTURA"}


def rows_from_report() -> dict[str, list[str]]:
    result = {}
    for line in DOC.read_text(encoding="utf-8").splitlines():
        if not re.match(r"^\| S(?:09|10|11)-R\d{3} \|", line):
            continue
        cells = [cell.strip() for cell in line.strip("|").split("|")]
        if len(cells) != 6:
            raise AssertionError(f"Fila no válida: {line[:100]}")
        if cells[0] in result:
            raise AssertionError(f"Fila duplicada: {cells[0]}")
        result[cells[0]] = cells
    return result


class ContrasteTests(unittest.TestCase):
    def test_cada_fila_tiene_veredicto(self) -> None:
        rows = rows_from_report()
        self.assertEqual(set(rows), EXPECTED)
        self.assertEqual(sum(v[1] == "SIN_COBERTURA" for v in rows.values()), 1)
        for key, (_, status, code, proof, source, reason) in rows.items():
            with self.subTest(key=key):
                self.assertIn(status, VALID)
                self.assertTrue(reason and reason != "—")
                self.assertTrue(source and source != "—")
                self.assertNotIn("NO_VERIFICABLE", reason)
                if status == "CUBIERTO":
                    self.assertNotEqual(code, "—")
                    self.assertNotEqual(proof, "—")
                elif status == "SIN_COBERTURA":
                    self.assertIn("BRECHA COMPROBADA", reason)
                else:
                    self.assertIn("Decisión", reason)

    def test_cubierto_enlaza_prueba_existente(self) -> None:
        for key, (_, status, code, proof, source, _) in rows_from_report().items():
            if status != "CUBIERTO":
                continue
            with self.subTest(key=key):
                for cell in (code, proof):
                    match = re.fullmatch(r"\[([^]]+)\]\(\.\./([^)]*)\)", cell)
                    self.assertIsNotNone(match, cell)
                    assert match is not None
                    self.assertEqual(match.group(1), match.group(2))
                    self.assertTrue((ROOT / match.group(2)).is_file(), match.group(2))
                self.assertRegex(proof, r"\(\.\./tests/")
                self.assertRegex(source, r"https://github\.com/pl0n3r/brvtal/pull/\d+")

    def test_faltante_tiene_issue(self) -> None:
        rows = rows_from_report()
        self.assertEqual(rows["S10-R011"][1], "SIN_COBERTURA")
        self.assertIn("https://github.com/pl0n3r/brvtal/issues/1065", rows["S10-R011"][4])
        for key, cells in rows.items():
            if cells[1] == "SIN_COBERTURA":
                self.assertRegex(cells[4], r"https://github\.com/pl0n3r/brvtal/issues/\d+")

    def test_matriz_actualizada(self) -> None:
        # Fail-closed: no se permite marcar AC-04 sin reclamar/actualizar matriz.
        matrix_rows = {}
        for line in MATRIX.read_text(encoding="utf-8").splitlines():
            match = re.match(r"^\| (S(?:09|10|11)-R\d{3}) · ", line)
            if match:
                matrix_rows[match.group(1)] = [c.strip() for c in line.strip("|").split("|")]
        report = rows_from_report()
        self.assertEqual(set(matrix_rows), EXPECTED)
        for key, cells in report.items():
            with self.subTest(key=key):
                self.assertEqual(matrix_rows[key][2], cells[1])
                self.assertNotIn("NO_VERIFICABLE", matrix_rows[key][2])
                if key != "S09-R004":
                    self.assertIn("contraste-spec_eventos.md", matrix_rows[key][3])
                if cells[1] == "SIN_COBERTURA":
                    self.assertIn("/issues/1065", matrix_rows[key][4])

    def test_lineup_api_routes_are_authenticated_csrf_and_atomic(self) -> None:
        # Contrasta literalmente el handler y su parser, sin conectarse a producción.
        src = (ROOT / "api/index.php").read_text(encoding="utf-8")
        start = src.index("if($resource==='events' && $action==='lineup' && $id!==null)")
        end = src.index("if($resource==='upload')", start)
        body = src[start:end]
        for token in (
            "$method==='GET'", "$method==='POST'",
            "brvtal_admin_require_csrf()", "$pdo->beginTransaction()",
            "DELETE FROM event_artists", "INSERT INTO event_artists",
            "$pdo->commit()", "$pdo->rollBack()", "SELECT ea.artist_id",
        ):
            with self.subTest(token=token):
                self.assertIn(token, body)
        self.assertLess(src.index("brvtal_admin_require();"), start)
        self.assertIn("if($resource==='events' && $action==='lineup'", body)


    def test_lineup_route_parser_in_php(self) -> None:
        # S11-R009: ejecuta el parser de producción para front controller y rewrite.
        source = r"""
require 'api/route.php';
echo json_encode([
    brvtal_api_parse_route('/api/index.php/events/12/lineup', '/api/index.php', 'GET'),
    brvtal_api_parse_route('/api/events/12/lineup', '/api/index.php', 'POST'),
    brvtal_api_parse_route('/api/events/12/lineup/unexpected', '/api/index.php', 'GET'),
], JSON_THROW_ON_ERROR);
"""
        result = subprocess.run(
            ["php", "-r", source], cwd=ROOT, capture_output=True,
            text=True, timeout=20, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        parsed = json.loads(result.stdout)
        self.assertEqual(
            [{key: row[key] for key in ("resource", "id", "action")} for row in parsed],
            [
                {"resource": "events", "id": 12, "action": "lineup"},
                {"resource": "events", "id": 12, "action": "lineup"},
                {"resource": "", "id": None, "action": ""},
            ],
        )


if __name__ == "__main__":
    unittest.main()
