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
    def assert_issue_inventory(self, report: str) -> None:
        """Validate the ten issue rows, including their own Markdown evidence links."""
        section = report.split("## Decisiones por issue", 1)[1].split(
            "## PRs abiertos y exclusividad", 1
        )[0]
        rows = [
            line for line in section.splitlines()
            if re.match(r"^\|\s*#\d+\s*\|", line)
        ]
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
            # A stray raw URL plus a Markdown link to a different issue MUST fail.
            self.assertRegex(
                evidence,
                r"\[[^\]]+\]\(" + re.escape(CANONICAL)
                + r"issues/" + str(number) + r"\)",
                f"Falta un enlace Markdown a evidencia propia de #{number}",
            )
            self.assertGreater(
                len(next_action.strip()), 55,
                f"Falta acción/autoridad concreta para #{number}",
            )
            self.assertNotIn("TODO", next_action)
            self.assertNotIn("PENDIENTE", decision)

        self.assertEqual(seen, EXPECTED_ISSUES)

    def assert_pr_inventory(self, report: str) -> None:
        """Validate full Markdown PR links, never a numeric-prefix substring."""
        pr_section = report.split("## PRs abiertos y exclusividad", 1)[1].split(
            "## Evidencia de la reconciliación", 1
        )[0]
        for pr in EXPECTED_PRS:
            self.assertRegex(
                pr_section,
                r"\\[[^\\]]+\\]\\(" + re.escape(CANONICAL + "pull/" + str(pr)) + r"\\)",
                f"PR abierto #{pr} omitido del inventario histórico",
            )

    def test_acceptance(self) -> None:
        self.assertTrue(DOCUMENT.is_file(), "Falta el inventario documentado del corte")
        report = DOCUMENT.read_text(encoding="utf-8")
        self.assertIn(MAIN_SHA, report, "Sin base exact-main del inventario")
        self.assertIn("24 de octubre de 2026", report)
        self.assertIn("## Decisiones por issue", report)
        self.assertIn("## PRs abiertos y exclusividad", report)
        self.assertIn("## Evidencia de la reconciliación", report)
        self.assert_issue_inventory(report)

        self.assert_pr_inventory(report)

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
        self.assert_issue_inventory(report)
        row = next(line for line in report.splitlines() if line.startswith("| #1016 |"))
        fields = [field.strip() for field in row.strip("|").split("|")]
        self.assertEqual(len(fields), 5)
        other_issue_link = (
            "[Evidencia ajena](" + CANONICAL + "issues/1009) "
            + CANONICAL + "issues/1016"
        )
        bad_rows = {
            "missing row": "",
            "duplicate row": row + "\n" + row,
            "wrong Markdown evidence link": "| " + " | ".join(
                fields[:3] + [other_issue_link] + fields[4:]
            ) + " |",
            "missing next action": "| " + " | ".join(fields[:4] + [""]) + " |",
            "premature closure": "| " + " | ".join(
                [fields[0], fields[1], "CERRAR sin merge", fields[3], fields[4]]
            ) + " |",
        }
        for case, replacement in bad_rows.items():
            with self.subTest(invalid_report=case):
                corrupted = report.replace(row, replacement, 1)
                self.assertNotEqual(corrupted, report)
                with self.assertRaises(AssertionError):
                    self.assert_issue_inventory(corrupted)


    def test_pr_link_prefix_is_not_historical_evidence(self) -> None:
        """A link to PR 9980 must never satisfy the required PR 998."""
        report = DOCUMENT.read_text(encoding="utf-8")
        good = f"[#998]({CANONICAL}pull/998)"
        wrong = f"[#998]({CANONICAL}pull/9980)"
        self.assertIn(good, report)
        corrupted = report.replace(good, wrong, 1)
        self.assertNotEqual(corrupted, report)
        with self.assertRaises(AssertionError):
            self.assert_pr_inventory(corrupted)

    def test_ci_calls_acceptance_on_exact_head(self) -> None:
        """Fail if the dated snapshot loses its independent exact-HEAD CI gate."""
        workflow = (ROOT / ".github/workflows/ci-backlog-reconciliation.yml").read_text(
            encoding="utf-8"
        )
        self.assertIn("name: BRVTAL Backlog Reconciliation Acceptance", workflow)
        self.assertIn("contents: read", workflow)
        self.assertIn("persist-credentials: false", workflow)
        self.assertIn("ref: ${{ github.event.pull_request.head.sha || github.sha }}", workflow)
        self.assertIn("'tests/test_reconciliacion_issues_20261024.py'", workflow)
        self.assertIn("'docs/reconciliacion-issues-20261024.md'", workflow)
        self.assertIn(
            "python3 -m unittest -v tests.test_reconciliacion_issues_20261024",
            workflow,
        )



if __name__ == "__main__":
    unittest.main()
