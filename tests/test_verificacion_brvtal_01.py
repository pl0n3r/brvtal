"""BRVTAL #1593: evidencia acotada de 50 filas; ninguna aserción de producción."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPORT = ROOT / "docs/verificacion/verificacion-01.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
CASES = (
    ('S02-R001', 'DECISION_PRODUCTO', '', 'tests/test_cobertura_brvtal_01.py', 'C'),
    ('S02-R002', 'QA_REAL_PENDIENTE', '', 'tests/test_cobertura_brvtal_01.py', 'B'),
    ('S02-R003', 'QA_REAL_PENDIENTE', '', 'tests/test_cobertura_brvtal_01.py', 'B'),
    ('S02-R004', 'QA_REAL_PENDIENTE', '', 'tests/test_cobertura_brvtal_01.py', 'B'),
    ('S02-R005', 'DECISION_PRODUCTO', '', 'tests/test_cobertura_brvtal_01.py', 'C'),
    ('S03-R001', 'QA_REAL_PENDIENTE', '', 'tests/test_cobertura_brvtal_01.py', 'B'),
    ('S03-R003', 'QA_REAL_PENDIENTE', '', 'tests/test_cobertura_brvtal_01.py', 'B'),
    ('S03-R004', 'REGRESION_PARCIAL', 'js/menu-accessibility.js', 'tests/test_cobertura_brvtal_01.py', 'A'),
    ('S03-R005', 'FUNCIONALIDAD_INCOMPLETA', '', '', 'D'),
    ('S03-R006', 'REGRESION_PARCIAL', 'js/public-concept05-motion.js', 'tests/test_cobertura_brvtal_01.py', 'A'),
    ('S03-R007', 'DECISION_PRODUCTO', '', 'tests/test_cobertura_brvtal_01.py', 'C'),
    ('S03-R008', 'QA_REAL_PENDIENTE', '', 'tests/test_cobertura_brvtal_01.py', 'B'),
    ('S03-R009', 'QA_REAL_PENDIENTE', '', 'tests/test_cobertura_brvtal_01.py', 'B'),
    ('S03-R010', 'FUNCIONALIDAD_INCOMPLETA', '', '', 'D'),
    ('S03-R011', 'REGRESION_PARCIAL', 'js/public-concept05-motion.js', 'tests/test_cobertura_brvtal_01.py', 'A'),
    ('S03-R012', 'DECISION_PRODUCTO', '', 'tests/test_cobertura_brvtal_01.py', 'C'),
    ('S03-R013', 'QA_REAL_PENDIENTE', '', 'tests/test_cobertura_brvtal_01.py', 'B'),
    ('S04-R001', 'QA_REAL_PENDIENTE', '', 'tests/test_cobertura_brvtal_01.py', 'B'),
    ('S06-R001', 'TESTIGO_HISTORICO_NO_PRESENTE', 'config/public_i18n.php', 'tests/test_cobertura_brvtal_02.py', 'A'),
    ('S06-R002', 'SPEC_DRIFT_ES_ONLY', 'config/public_i18n.php', 'tests/test_cobertura_brvtal_02.py', 'A'),
    ('S06-R003', 'SPEC_DRIFT_ES_ONLY', 'config/public_i18n.php', 'tests/test_cobertura_brvtal_02.py', 'A'),
    ('S06-R004', 'TESTIGO_HISTORICO_NO_PRESENTE', 'config/public_i18n.php', 'tests/test_cobertura_brvtal_02.py', 'A'),
    ('S06-R005', 'TESTIGO_HISTORICO_NO_PRESENTE', 'config/public_i18n.php', 'tests/test_cobertura_brvtal_02.py', 'A'),
    ('S06-R006', 'TESTIGO_HISTORICO_NO_PRESENTE', 'config/public_i18n.php', 'tests/test_cobertura_brvtal_02.py', 'A'),
    ('S06-R007', 'TESTIGO_HISTORICO_NO_PRESENTE', '', 'tests/test_cobertura_brvtal_02.py', 'C'),
    ('S06-R008', 'TESTIGO_HISTORICO_NO_PRESENTE', '', 'tests/test_cobertura_brvtal_02.py', 'C'),
    ('S06-R010', 'SOURCE_AUSENTE', 'config/public_translation_overlay.php', 'tests/test_cobertura_brvtal_02.py', 'A'),
    ('S06-R011', 'SOURCE_AUSENTE', 'config/public_translation_overlay.php', 'tests/test_cobertura_brvtal_02.py', 'A'),
    ('S07-R002', 'TESTIGO_HISTORICO_NO_PRESENTE', '', 'tests/test_cobertura_brvtal_02.py', 'B'),
    ('S07-R003', 'QA_REAL_PENDIENTE', '', 'tests/test_cobertura_brvtal_03.py', 'B'),
    ('S07-R005', 'REGRESION_PARCIAL', 'config/public_routes.php', 'tests/test_cobertura_brvtal_03.py', 'A'),
    ('S07-R006', 'REGRESION_PARCIAL', 'config/public_routes.php', 'tests/test_cobertura_brvtal_03.py', 'A'),
    ('S07-R007', 'REGRESION_PARCIAL', 'config/public_routes.php', 'tests/test_cobertura_brvtal_03.py', 'A'),
    ('S07-R008', 'REGRESION_PARCIAL', 'config/public_routes.php', 'tests/test_cobertura_brvtal_03.py', 'A'),
    ('S07-R009', 'REGRESION_PARCIAL', 'config/public_routes.php', 'tests/test_cobertura_brvtal_03.py', 'A'),
    ('S07-R010', 'DECISION_PRODUCTO', '', 'tests/test_cobertura_brvtal_03.py', 'C'),
    ('S07-R011', 'REGRESION_PARCIAL', 'config/public_routes.php', 'tests/test_cobertura_brvtal_03.py', 'A'),
    ('S08-R001', 'DECISION_PRODUCTO', '', 'tests/test_cobertura_brvtal_03.py', 'C'),
    ('S12-R001', 'QA_REAL_PENDIENTE', '', 'tests/test_cobertura_brvtal_04.py', 'B'),
    ('S12-R002', 'QA_REAL_PENDIENTE', '', 'tests/test_cobertura_brvtal_04.py', 'B'),
    ('S12-R003', 'QA_REAL_PENDIENTE', '', 'tests/test_cobertura_brvtal_04.py', 'B'),
    ('S12-R005', 'REGRESION_PARCIAL', 'config/artist_collective_membership.php', 'tests/test_cobertura_brvtal_04.py', 'A'),
    ('S12-R006', 'QA_REAL_PENDIENTE', '', 'tests/test_cobertura_brvtal_04.py', 'B'),
    ('S12-R007', 'REGRESION_PARCIAL', 'config/artist_collective_membership.php', 'tests/test_cobertura_brvtal_04.py', 'A'),
    ('S13-R001', 'DECISION_PRODUCTO', '', 'tests/test_cobertura_brvtal_04.py', 'C'),
    ('S13-R002', 'REGRESION_PARCIAL', 'config/set_publication.php', 'tests/test_cobertura_brvtal_04.py', 'A'),
    ('S13-R003', 'REGRESION_PARCIAL', 'config/set_publication.php', 'tests/test_cobertura_brvtal_04.py', 'A'),
    ('S13-R004', 'QA_REAL_PENDIENTE', '', 'tests/test_cobertura_brvtal_04.py', 'B'),
    ('S14-R001', 'DECISION_PRODUCTO', '', 'tests/test_cobertura_brvtal_04.py', 'C'),
    ('S14-R003', 'DECISION_PRODUCTO', '', 'tests/test_cobertura_brvtal_04.py', 'C'),
)
EXPECTED_KINDS = {
    "REGRESION_PARCIAL", "QA_REAL_PENDIENTE", "DECISION_PRODUCTO",
    "FUNCIONALIDAD_INCOMPLETA", "SPEC_DRIFT_ES_ONLY",
    "SOURCE_AUSENTE", "TESTIGO_HISTORICO_NO_PRESENTE",
}
REAL_SUITES = (
    "tests.test_cobertura_brvtal_03",
    "tests.test_cobertura_brvtal_04",
    "tests.test_public_i18n_contract",
    "tests.test_public_i18n_routing",
    "tests.test_public_language_selector",
    "tests.test_public_spanish_only",
)


def parse_report(text: str) -> dict[str, tuple[str, str, str]]:
    """Rechaza filas repetidas, faltantes y promociones no justificadas."""
    rows: dict[str, tuple[str, str, str]] = {}
    for line in text.splitlines():
        if not line.startswith("| `S"):
            continue
        parts = line.split(" | ")
        if len(parts) != 6:
            raise ValueError("Fila mal formada")
        identifier = parts[0].removeprefix("| `").removesuffix("`")
        state, kind, witness = parts[2], parts[3], parts[4]
        if identifier in rows or not re.fullmatch(r"S\d{2}-R\d{3}", identifier):
            raise ValueError("ID duplicado o inválido")
        if state != "NO_VERIFICABLE" or kind not in EXPECTED_KINDS:
            raise ValueError("Promoción de cobertura o clase inválida")
        rows[identifier] = (state, kind, witness)
    if len(rows) != 50:
        raise ValueError("Inventario incompleto")
    return rows


class VerificacionBrvtal01Tests(unittest.TestCase):
    """Regresión de trazabilidad con datos ficticios y contratos originales."""

    def test_50_identidades_exactas_y_clasificacion(self):
        self.assertEqual(len(CASES), 50)
        self.assertEqual(len({x[0] for x in CASES}), 50)
        rows = parse_report(REPORT.read_text(encoding="utf-8"))
        self.assertEqual(set(rows), {x[0] for x in CASES})
        matrix = MATRIX.read_text(encoding="utf-8").splitlines()
        for identifier, kind, source, witness, original_class in CASES:
            with self.subTest(identifier=identifier):
                self.assertEqual(rows[identifier][:2], ("NO_VERIFICABLE", kind))
                exact = [line for line in matrix if line.startswith("| " + identifier + " ")]
                self.assertEqual(len(exact), 1)
                self.assertIn(" | NO_VERIFICABLE | " + original_class + " · ", exact[0])
                if kind in ("REGRESION_PARCIAL", "QA_REAL_PENDIENTE"):
                    self.assertTrue(source or witness)
                if kind == "SOURCE_AUSENTE":
                    self.assertFalse((ROOT / source).exists())
                elif kind in ("TESTIGO_HISTORICO_NO_PRESENTE", "SPEC_DRIFT_ES_ONLY"):
                    self.assertFalse((ROOT / witness).exists())
                else:
                    if source:
                        self.assertTrue((ROOT / source).is_file(), source)
                    if witness:
                        self.assertTrue((ROOT / witness).is_file(), witness)

    def test_falla_cerrado_con_filas_falsas_y_promocion(self):
        original = REPORT.read_text(encoding="utf-8")
        self.assertEqual(len(parse_report(original)), 50)
        line = next(x for x in original.splitlines() if x.startswith("| `S02-R001`"))
        for altered in (
            original.replace(line, "", 1),
            original.replace(line, line + chr(10) + line, 1),
            original.replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1),
            original.replace(line, line.replace("DECISION_PRODUCTO", "CUBIERTO", 1), 1),
        ):
            with self.assertRaises(ValueError):
                parse_report(altered)

    def test_locale_english_no_se_declara_activo(self):
        rows = parse_report(REPORT.read_text(encoding="utf-8"))
        for identifier in ("S06-R002", "S06-R003"):
            self.assertEqual(rows[identifier][1], "SPEC_DRIFT_ES_ONLY")
        self.assertIn("únicamente ES", REPORT.read_text(encoding="utf-8"))

    def test_contratos_reales_preexistentes(self):
        """Ejecuta las suites originales, nunca prueba conectividad productiva."""
        suite = unittest.defaultTestLoader.loadTestsFromNames(REAL_SUITES)
        result = unittest.TestResult()
        suite.run(result)
        self.assertGreaterEqual(result.testsRun, 20)
        self.assertEqual(result.failures, [], str(result.failures))
        self.assertEqual(result.errors, [], str(result.errors))


if __name__ == "__main__":
    unittest.main()
