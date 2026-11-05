"""BRVTAL #1594: validación conservadora del lote 02, sin uso de producción."""
from __future__ import annotations
import re
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
REPORT=ROOT/"docs/verificacion/verificacion-02.md"
MATRIX=ROOT/"docs/matriz-cobertura.md"
CASES=(
    ('S15-R001', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_04.py', 'B'),
    ('S15-R002', 'REGRESION_LOCAL_PARCIAL', 'api/event-workflow-lib.php', 'tests/test_cobertura_brvtal_04.py', 'A'),
    ('S15-R003', 'REGRESION_LOCAL_PARCIAL', 'api/event-workflow-lib.php', 'tests/test_cobertura_brvtal_05.py', 'A'),
    ('S15-R005', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_05.py', 'B'),
    ('S15-R006', 'DECISION_PRODUCTO', '', 'tests/test_cobertura_brvtal_05.py', 'C'),
    ('S15-R007', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_05.py', 'B'),
    ('S16-R001', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_05.py', 'B'),
    ('S16-R002', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_05.py', 'B'),
    ('S16-R003', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_05.py', 'B'),
    ('S16-R004', 'REGRESION_LOCAL_PARCIAL', 'api/public-archive.php', 'tests/test_cobertura_brvtal_05.py', 'A'),
    ('S16-R006', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_05.py', 'B'),
    ('S16-R007', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_05.py', 'B'),
    ('S17-R001', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_05.py', 'B'),
    ('S17-R002', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_05.py', 'B'),
    ('S17-R003', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_05.py', 'B'),
    ('S17-R004', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_05.py', 'B'),
    ('S17-R005', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_05.py', 'B'),
    ('S17-R007', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_05.py', 'B'),
    ('S18-R003', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_05.py', 'B'),
    ('S18-R004', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_05.py', 'B'),
    ('S18-R006', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_05.py', 'B'),
    ('S18-R009', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_05.py', 'B'),
    ('S18-R012', 'REGRESION_LOCAL_PARCIAL', 'config/media.php', 'tests/test_cobertura_brvtal_06.py', 'A'),
    ('S18-R014', 'REGRESION_LOCAL_PARCIAL', 'config/media.php', 'tests/test_cobertura_brvtal_06.py', 'A'),
    ('S18-R015', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_06.py', 'B'),
    ('S18-R016', 'REGRESION_LOCAL_PARCIAL', 'config/media.php', 'tests/test_cobertura_brvtal_06.py', 'A'),
    ('S18-R017', 'REGRESION_LOCAL_PARCIAL', 'config/media.php', 'tests/test_cobertura_brvtal_06.py', 'A'),
    ('S18-R020', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_06.py', 'B'),
    ('S18-R021', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_06.py', 'B'),
    ('S18-R025', 'DECISION_PRODUCTO', '', 'tests/test_cobertura_brvtal_06.py', 'C'),
    ('S19-R001', 'REGRESION_LOCAL_PARCIAL', 'config/page_content.php', 'tests/test_cobertura_brvtal_06.py', 'A'),
    ('S19-R002', 'DECISION_PRODUCTO', '', 'tests/test_cobertura_brvtal_06.py', 'C'),
    ('S20-R002', 'DECISION_PRODUCTO', '', 'tests/test_cobertura_brvtal_06.py', 'C'),
    ('S20-R003', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_06.py', 'B'),
    ('S20-R005', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_06.py', 'B'),
    ('S20-R006', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_06.py', 'B'),
    ('S20-R008', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_06.py', 'B'),
    ('S20-R009', 'DECISION_PRODUCTO', '', 'tests/test_cobertura_brvtal_06.py', 'C'),
    ('S20-R010', 'DECISION_PRODUCTO', '', 'tests/test_cobertura_brvtal_06.py', 'C'),
    ('S20-R011', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_06.py', 'B'),
    ('S20-R012', 'REGRESION_LOCAL_PARCIAL', 'config/public_preview.php', 'tests/test_cobertura_brvtal_06.py', 'A'),
    ('S20-R013', 'REGRESION_LOCAL_PARCIAL', 'config/public_preview.php', 'tests/test_cobertura_brvtal_06.py', 'A'),
    ('S20-R017', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_07.py', 'B'),
    ('S20-R018', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_07.py', 'B'),
    ('S20-R019', 'QA_INTEGRACION_PENDIENTE', '', 'tests/test_cobertura_brvtal_07.py', 'B'),
    ('S20-R020', 'REGRESION_LOCAL_PARCIAL', '', 'tests/test_cobertura_brvtal_07.py', 'A'),
    ('S20-R021', 'REGRESION_LOCAL_PARCIAL', '', 'tests/test_cobertura_brvtal_07.py', 'A'),
    ('S20-R022', 'DECISION_PRODUCTO', '', 'tests/test_cobertura_brvtal_07.py', 'C'),
    ('S21-R002', 'REGRESION_LOCAL_PARCIAL', '', 'tests/test_cobertura_brvtal_07.py', 'A'),
    ('S21-R003', 'REGRESION_LOCAL_PARCIAL', '', 'tests/test_cobertura_brvtal_07.py', 'A'),
)
ALLOWED={"REGRESION_LOCAL_PARCIAL","QA_INTEGRACION_PENDIENTE","DECISION_PRODUCTO",
         "FUENTE_NO_PRESENTE","TESTIGO_NO_PRESENTE"}


def rows_of_report(text):
    """Comprueba que un inventario sintético no eleve cobertura."""
    result={}
    for line in text.splitlines():
        if not line.startswith("| `S"): continue
        parts=line.split(" | ")
        if len(parts)!=6: raise ValueError("fila corrupta")
        key=parts[0].removeprefix("| `").removesuffix("`")
        if not re.fullmatch(r"S[0-9]{2}-R[0-9]{3}",key) or key in result: raise ValueError("id duplicado")
        if parts[2]!="NO_VERIFICABLE" or parts[3] not in ALLOWED: raise ValueError("cobertura inflada")
        result[key]=parts
    if len(result)!=50: raise ValueError("inventario faltante")
    return result


class VerificacionBrvtal02Tests(unittest.TestCase):
    def test_50_ids_exactos_y_fuentes(self):
        self.assertEqual(len(CASES),50)
        rows=rows_of_report(REPORT.read_text(encoding="utf-8"))
        self.assertEqual(set(rows),{x[0] for x in CASES})
        matrix=MATRIX.read_text(encoding="utf-8").splitlines()
        for key,kind,source,witness,origin in CASES:
            with self.subTest(key=key):
                self.assertEqual(rows[key][3],kind)
                matched=[x for x in matrix if x.startswith("| "+key+" ")]
                self.assertEqual(len(matched),1)
                self.assertIn("| NO_VERIFICABLE | "+origin+" ·",matched[0])
                self.assertEqual(rows[key][1].replace("\\|", "|"), matched[0].split(" | ")[1])
                expected_ref = source or witness or "[sin testigo individual]"
                if source and witness:
                    expected_ref += " ; " + witness
                self.assertEqual(rows[key][4], "`" + expected_ref + "`")
                if kind=="FUENTE_NO_PRESENTE": self.assertFalse((ROOT/source).exists())
                elif kind=="TESTIGO_NO_PRESENTE": self.assertFalse((ROOT/witness).exists())
                else:
                    if source: self.assertTrue((ROOT/source).is_file())
                    if witness: self.assertTrue((ROOT/witness).is_file())

    def test_mutaciones_negativas_fallan_cerrado(self):
        original=REPORT.read_text(encoding="utf-8")
        line=next(x for x in original.splitlines() if x.startswith("| `S15-R001`"))
        for wrong in (
            original.replace(line,"",1),
            original.replace(line,line+chr(10)+line,1),
            original.replace(line,line.replace("NO_VERIFICABLE","CUBIERTO"),1),
        ):
            with self.assertRaises(ValueError): rows_of_report(wrong)

    def test_testigos_ejecutables_seleccionados(self):
        suite=unittest.defaultTestLoader.loadTestsFromNames((
            "tests.test_cobertura_brvtal_05","tests.test_cobertura_brvtal_07",
            "tests.test_public_preview_popup_fail_closed",
        ))
        result=unittest.TestResult()
        suite.run(result)
        self.assertGreaterEqual(result.testsRun,8)
        self.assertEqual(result.failures,[],str(result.failures))
        self.assertEqual(result.errors,[],str(result.errors))

    def test_sin_produccion_ni_promocion(self):
        text=REPORT.read_text(encoding="utf-8")
        self.assertIn("cero promociones",text)
        self.assertNotIn("| CUBIERTO |",text)
        self.assertEqual(len(CASES),50)

if __name__=="__main__":
    unittest.main()
