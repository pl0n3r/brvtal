"""QA offline de la matriz pre-live BRVTAL #1036. No ejecuta red ni go-live."""
import re
import unittest
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "lista-salida-produccion.md"
STATES = {"LISTO", "EN_CURSO", "SIN_COBERTURA", "DEL_DUEÑO", "NO_APLICA"}
AREAS = {
    "Respaldos y restauración", "Copias de seguridad finales",
    "Monitoreo y alertas", "Dominios/DNS/TLS", "Secretos y accesos",
    "Costos y límites", "Privacidad y aspectos legales",
    "Soporte y operación diaria", "Migración de datos",
    "Reversión y apagado de emergencia", "Seguridad (revisión y pruebas)",
    "Accesibilidad y rendimiento", "Smoke de producción",
    "Contenido real y medios definitivos", "Imágenes del home",
    "Traducción ES/EN y DeepL", "Aprobación visual del dueño",
    "SEO e indexación en producción", "Autorización final de live",
}
ISSUE = re.compile(r"\[#(\d+)\]\(https://github\.com/pl0n3r/brvtal/issues/\1\)")

def matrix():
    content = DOC.read_text(encoding="utf-8")
    section = content.split("## Matriz de salida\n", 1)[1].split("\n## Resumen final", 1)[0]
    data = []
    for line in section.splitlines():
        if not line.startswith("| "):
            continue
        fields = [field.strip() for field in line.strip().strip("|").split("|")]
        if fields[0] == "Área" or all(re.fullmatch(r"[-: ]+", x) for x in fields):
            continue
        data.append(fields)
    return content, data

class ListaSalidaTests(unittest.TestCase):
    def test_cubre_todo_el_alcance(self):
        text, rows = matrix()
        self.assertGreaterEqual(len(rows), 30)
        self.assertTrue(AREAS <= {r[0] for r in rows})
        for snippet in ("#975", "#1033", "#1034", "DeepL", "1440/390",
                        "aprobación", "indexación", "restauración", "derechos"):
            self.assertIn(snippet, text)
        self.assertIn("No autoriza go-live", text)

    def test_sin_cobertura_tiene_issue(self):
        _, rows = matrix()
        uncovered = [r for r in rows if r[2] == "SIN_COBERTURA"]
        self.assertGreaterEqual(len(uncovered), 6)
        ids = []
        for r in uncovered:
            self.assertEqual(len(r), 5)
            self.assertTrue(r[3])
            match = ISSUE.fullmatch(r[4])
            self.assertIsNotNone(match, r)
            ids.append(int(match.group(1)))
            self.assertIn(int(match.group(1)), range(1039, 1045))
        self.assertEqual(set(ids), set(range(1039, 1045)))

    def test_formato_y_estados(self):
        text, rows = matrix()
        self.assertIn("| Área | Requisito | Estado | Evidencia | Issue |", text)
        seen = set()
        for r in rows:
            self.assertEqual(len(r), 5, r)
            area, req, state, evidence, issue = r
            self.assertTrue(all(r), r)
            self.assertIn(state, STATES)
            self.assertNotIn((area, req), seen)
            seen.add((area, req))
            self.assertIsNotNone(ISSUE.fullmatch(issue))
            if state == "LISTO":
                self.assertRegex(evidence, r"(?:tests/|docs/)")
            elif state == "EN_CURSO":
                self.assertRegex(evidence, r"PR #\d+")
                self.assertNotIn("/issues/533", issue)
            elif state == "DEL_DUEÑO":
                self.assertRegex(evidence.lower(), r"(?:dueño|titular|responsable|aprob)")
            elif state == "NO_APLICA":
                self.assertRegex(evidence.lower(), r"(?:exclusión|fuera de alcance)")

    def test_resumen_final(self):
        text, rows = matrix()
        summary = text.split("## Resumen final\n", 1)[1]
        counts = Counter(r[2] for r in rows)
        for state in STATES:
            match = re.search(r"^- " + state + r": (\d+)$", summary, re.M)
            self.assertIsNotNone(match, state)
            self.assertEqual(int(match.group(1)), counts[state])
        match = re.search(r"^- TOTAL: (\d+)$", summary, re.M)
        self.assertIsNotNone(match)
        self.assertEqual(int(match.group(1)), len(rows))
        self.assertIn("Impedimentos para go-live", summary)
        self.assertIn("#1039–#1044", summary)
        self.assertIn("No hay permiso implícito para desplegar", summary)

if __name__ == "__main__":
    unittest.main()
