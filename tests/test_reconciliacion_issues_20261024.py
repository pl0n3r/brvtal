#!/usr/bin/env python3
"""AC-01: corte auditable del backlog BRVTAL, sin red ni credenciales."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOCUMENT = ROOT / "docs/reconciliacion-issues-20261024.md"
EXPECTED_ISSUES = {1016, 1014, 1009, 987, 985, 975, 974, 973, 533, 212}
EXPECTED_PRS = {1015, 1012, 1008, 999, 998}
CANONICAL = "https://github.com/pl0n3r/brvtal/"
MAIN_SHA = "4edbf3fdb4302f3c1034e27f007ba09638cfeebc"
VALID_DECISIONS = ("ENTREGAR PR", "ACTUALIZAR", "MANTENER ABIERTO")


class ReconciliacionIssuesTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(DOCUMENT.is_file(), "Falta el inventario documentado del corte")
        report = DOCUMENT.read_text(encoding="utf-8")
        self.assertIn(MAIN_SHA, report, "Sin base exact-main del inventario")
        self.assertIn("24 de octubre de 2026", report)
        self.assertIn("## Decisiones por issue", report)
        self.assertIn("## PRs abiertos y exclusividad", report)
        self.assertIn("## Evidencia de la reconciliación", report)

        # Parse only the authoritative five-column issue inventory, not prose or
        # other hyperlinks/PRs. Keep this snapshot deterministic after new merges.
        section = report.split("## Decisiones por issue", 1)[1].split(
            "## PRs abiertos y exclusividad", 1
        )[0]
        rows = [line for line in section.splitlines()
                if re.match(r"^\|\s*#\d+\s*\|", line)]
        self.assertEqual(len(rows), len(EXPECTED_ISSUES), "Filas ausentes o duplicadas")
        seen: set[int] = set()

        for line in rows:
            cells = [cell.strip() for cell in line.strip().strip("|").split("|")]
            self.assertEqual(len(cells), 5, "Cada issue requiere cinco columnas")
            issue, status, decision, evidence, next_action = cells
            number = int(issue.lstrip("#"))
            self.assertIn(number, EXPECTED_ISSUES)
            self.assertNotIn(number, seen, "Issue duplicado")
            seen.add(number)
            self.assertTrue(status, f"Falta el estado de #{number}")
            self.assertTrue(
                any(decision.startswith(value) for value in VALID_DECISIONS),
                f"Decisión ambigua o cierre no acreditado en #{number}",
            )
            self.assertRegex(
                evidence,
                re.escape(CANONICAL) + r"issues/" + str(number) + r"\b",
                f"Falta enlace a evidencia propia de #{number}",
            )
            self.assertRegex(
                evidence, r"\[[^\]]+\]\(https://github\.com/pl0n3r/brvtal/issues/\d+\)",
                f"Referencia a GitHub inválida en #{number}",
            )
            self.assertGreater(
                len(next_action.strip()), 55,
                f"Falta acción/autoridad concreta para #{number}",
            )
            self.assertNotIn("TODO", next_action)
            self.assertNotIn("PENDIENTE", decision)

        self.assertEqual(seen, EXPECTED_ISSUES)
        self.assertEqual(len(rows), 10)
        for pr in EXPECTED_PRS:
            self.assertIn(
                f"https://github.com/pl0n3r/brvtal/pull/{pr}", report,
                f"PR abierto #{pr} omitido del corte",
            )

        for safeguard in (
            "SonarCloud Code Analysis",
            "validate",
            "15368",
            "12526",
            "#1009",
            "datos.yml.phase=construccion",
            "readme/project.json.phase=live",
            "Ningún Issue se ha cerrado",
            "No cerrar una épica",
        ):
            self.assertIn(safeguard, report, safeguard)

    def test_each_evidence_row_remains_fail_closed(self) -> None:
        report = DOCUMENT.read_text(encoding="utf-8")
        lines = [line for line in report.splitlines()
                 if re.match(r"^\|\s*#\d+\s*\|", line)]
        self.assertEqual(len(lines), 10)
        for row in lines:
            decision = row.split("|")[3].strip().upper()
            self.assertFalse(
                decision.startswith("CERRAR"),
                "No se puede cerrar una línea sin un merge y aceptación reales",
            )


if __name__ == "__main__":
    unittest.main()
