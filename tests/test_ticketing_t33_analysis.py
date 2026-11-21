#!/usr/bin/env python3
"""Contratos documentales T33, sin red, sesiones, proveedor ni datos de clientes."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

DOC = (
    Path(__file__).resolve().parents[1]
    / "docs/ticketing/T33-evaluacion-wallet-fidelizacion-merchandising.md"
)
REQUIRED = (
    "Apple Wallet",
    "Google Wallet",
    "Pase PDF",
    "Fidelización",
    "Entrada + merchandising",
)
HEADERS = (
    "Opción", "Valor", "Esfuerzo", "Dependencias",
    "Riesgos de seguridad / operación", "Recomendación", "Fase",
)


def parse_matrix(document: str) -> dict[str, list[str]]:
    section = document.split("## Matriz valor / esfuerzo y riesgos\n", 1)[1]
    section = section.split("\n## ", 1)[0]
    rows = [line for line in section.splitlines() if line.startswith("|")]
    if len(rows) < 3:
        raise ValueError("Matriz ausente o incompleta")
    cells = lambda line: [item.strip() for item in line.strip("|").split("|")]
    if tuple(cells(rows[0])) != HEADERS:
        raise ValueError("Columnas de valoración o trazabilidad ausentes")
    if not all(re.fullmatch(r":?-{3,}:?", c) for c in cells(rows[1])):
        raise ValueError("Separador de tabla inválido")
    result = {}
    for raw in rows[2:]:
        values = cells(raw)
        if len(values) != len(HEADERS) or not all(values):
            raise ValueError("Fila sin valoración, riesgo o dependencia")
        if values[0] in result:
            raise ValueError("Opción duplicada")
        if values[1] not in ("Alto", "Medio", "Bajo") or values[2] not in ("Alto", "Medio", "Bajo"):
            raise ValueError("Valor/esfuerzo no cualitativos")
        if "Aplazar" not in values[5] and "Priorizar **análisis**" not in values[5]:
            raise ValueError("Recomendación sin barrera de activación")
        if values[6] != "P6 post-MVP":
            raise ValueError("Opción adelantada al MVP")
        result[values[0]] = values
    if set(result) != set(REQUIRED):
        raise ValueError("Alternativas requeridas incompletas")
    return result


def assert_no_live(document: str) -> None:
    for required in (
        "NO_LIVE / NO_GO", "Sin integraciones habilitadas", "DECISION_PENDIENTE",
        "No crear hojas ni reservas especulativas", "Wompi/QR/checkout",
        "No equivalencia", "datos reales", "revisión legal",
    ):
        if required not in document:
            raise ValueError("Falta límite de seguridad o MVP: " + required)
    if re.search(r"(?im)^\s*```|\b(?:npm install|composer require|curl -X|gh api|CREATE TABLE|INSERT INTO)\b", document):
        raise ValueError("Instrucciones ejecutables o cambios de proveedor en el informe")


class TicketingT33AnalysisTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.document = DOC.read_text(encoding="utf-8")

    def test_matrix_covers_all_alternatives_and_dependencies(self) -> None:
        data = parse_matrix(self.document)
        self.assertEqual(set(data), set(REQUIRED))
        self.assertIn("certificado", data["Apple Wallet"][3])
        self.assertIn("issuer", data["Google Wallet"][3])
        self.assertIn("revocación", data["Pase PDF"][3])
        self.assertIn("consentimiento", data["Fidelización"][3])
        self.assertIn("SKU/stock", data["Entrada + merchandising"][3])

    def test_no_live_activation_or_supplier_integration(self) -> None:
        assert_no_live(self.document)
        self.assertIn("WooCommerce", self.document)
        self.assertIn("sin proveedor nuevo", self.document)
        self.assertIn("Cada opción **aprobada explícitamente**", self.document)

    def test_matrix_rejects_missing_or_unapproved_options(self) -> None:
        data = self.document.replace("| Google Wallet |", "| Wallet omitido |")
        with self.assertRaises(ValueError):
            parse_matrix(data)
        data = self.document.replace("Aplazar; evaluar", "Activar ya; evaluar")
        with self.assertRaises(ValueError):
            parse_matrix(data)

    def test_safety_regression_rejects_removed_no_live(self) -> None:
        data = self.document.replace("NO_LIVE / NO_GO", "LIVE aprobado")
        with self.assertRaises(ValueError):
            assert_no_live(data)


if __name__ == "__main__":
    unittest.main()
