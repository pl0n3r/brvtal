#!/usr/bin/env python3
"""#1598: audit 50 BRVTAL rows by executing existing PHP/JS-backed contracts."""
from __future__ import annotations

import ast
import io
import json
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tests"))
import test_cobertura_brvtal_16 as suite16
import test_cobertura_brvtal_17 as suite17
import test_cobertura_brvtal_18 as suite18
import test_cobertura_brvtal_19 as suite19

REPORT = ROOT / "docs/verificacion/verificacion-06.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
CASES = json.loads(r'''[{"id":"S40-R005","kind":"B","req":"event_ticket_types;","source":"database/schema.sql","probe":"tests/test_cobertura_brvtal_16.py::CoberturaBrvtal16Tests.test_contrato_principal","gap":"Falta presencia de «event_ticket_types;» en esquema MariaDB efectivamente migrado (DDL versionado y PDO sintético no acreditan despliegue).","group":16},{"id":"S40-R006","kind":"B","req":"artist_collective_history;","source":"database/schema.sql","probe":"tests/test_cobertura_brvtal_16.py::CoberturaBrvtal16Tests.test_contrato_principal","gap":"Falta presencia de «artist_collective_history;» en esquema MariaDB efectivamente migrado (DDL versionado y PDO sintético no acreditan despliegue).","group":16},{"id":"S40-R007","kind":"B","req":"releases and release relation/link tables;","source":"database/schema.sql","probe":"tests/test_cobertura_brvtal_16.py::CoberturaBrvtal16Tests.test_contrato_principal","gap":"Falta presencia de «releases and release relation/link tables;» en esquema MariaDB efectivamente migrado (DDL versionado y PDO sintético no acreditan despliegue).","group":16},{"id":"S40-R008","kind":"B","req":"blog_posts, blog_tags, blog_post_tags, blog_post_relations;","source":"database/schema.sql","probe":"tests/test_cobertura_brvtal_16.py::CoberturaBrvtal16Tests.test_contrato_principal","gap":"Falta presencia de «blog_posts, blog_tags, blog_post_tags, blog_post_relations;» en esquema MariaDB efectivamente migrado (DDL versionado y PDO sintético no acreditan despliegue).","group":16},{"id":"S40-R009","kind":"B","req":"admin_activity_log.","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_17.py::CoberturaBrvtal17Tests.test_contrato_principal","gap":"Falta inspección del esquema MariaDB desplegado para la tabla admin_activity_log.","group":17},{"id":"S40-R010","kind":"C","req":"This list may evolve.","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_17.py::CoberturaBrvtal17Tests.test_contrato_principal","gap":"«This list may evolve.» es una regla, decisión o encabezado documental sin observable atómico en","group":17},{"id":"S40-R011","kind":"B","req":"Production migrations already applied include the Content Core, Releases, Blog, SEO and Admin Activity foundations.","source":"config/migrations.php","probe":"tests/test_cobertura_brvtal_17.py::CoberturaBrvtal17Tests.test_contrato_principal","gap":"Falta recibos exactos de migraciones ejecutadas y conciliación de versiones/esquema productivo.","group":17},{"id":"S40-R012","kind":"A","req":"Do not re-run previously applied production migrations blindly.","source":"config/migrations.php","probe":"tests/test_cobertura_brvtal_17.py::CoberturaBrvtal17Tests.test_contrato_principal","gap":"Código real `config/migrations.php` contrastado con «Do not re-run previously applied production migrations blindly.» por","group":17},{"id":"S40-R013","kind":"B","req":"TOTP compatibility/key work reused existing schema/settings where possible and did not require a new production migration for the persistent encryption key.","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_17.py::CoberturaBrvtal17Tests.test_contrato_principal","gap":"Falta historial de compatibilidad TOTP y claves en servidor/DB autorizado.","group":17},{"id":"S41-R001","kind":"A","req":"Public and admin APIs must remain clearly separated.","source":"api/route.php","probe":"tests/test_cobertura_brvtal_17.py::CoberturaBrvtal17Tests.test_contrato_principal","gap":"Código real `api/route.php` contrastado con «Public and admin APIs must remain clearly separated.» por","group":17},{"id":"S41-R002","kind":"A","req":"api/public.php is read-only and allowlisted.","source":"api/route.php","probe":"tests/test_cobertura_brvtal_17.py::CoberturaBrvtal17Tests.test_contrato_principal","gap":"Código real `api/route.php` contrastado con «api/public.php is read-only and allowlisted.» por","group":17},{"id":"S41-R003","kind":"A","req":"api/index.php handles authenticated admin resources and may provide a backwards-compatible public route only by delegating to api/public.php.","source":"api/route.php","probe":"tests/test_cobertura_brvtal_17.py::CoberturaBrvtal17Tests.test_contrato_principal","gap":"Código real `api/route.php` contrastado con «api/index.php handles authenticated admin resources and may provide a backwards-compa» por","group":17},{"id":"S41-R004","kind":"A","req":"Never maintain two divergent public data implementations.","source":"api/route.php","probe":"tests/test_cobertura_brvtal_17.py::CoberturaBrvtal17Tests.test_contrato_principal","gap":"Código real `api/route.php` contrastado con «Never maintain two divergent public data implementations.» por","group":17},{"id":"S41-R005","kind":"A","req":"Canonical event-participation route parsing must support both front-controller and compatible rewritten forms of /events/{id}/lineup.","source":"api/route.php","probe":"tests/test_cobertura_brvtal_17.py::CoberturaBrvtal17Tests.test_contrato_principal","gap":"Código real `api/route.php` contrastado con «Canonical event-participation route parsing must support both front-controller and co» por","group":17},{"id":"S41-R006","kind":"B","req":"Public entity delivery must filter draft/private content and private related records at the server/API layer, not merely hide them visually.","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_17.py::CoberturaBrvtal17Tests.test_contrato_principal","gap":"Falta HTTP real y BD con registros borrador/privados y relaciones privadas para comprobar filtrado.","group":17},{"id":"S42-R001","kind":"A","req":"prepared statements;","source":"config/admin_auth.php","probe":"tests/test_cobertura_brvtal_17.py::CoberturaBrvtal17Tests.test_contrato_principal","gap":"Código real `config/admin_auth.php` contrastado con «prepared statements;» por","group":17},{"id":"S42-R002","kind":"A","req":"secure sessions;","source":"config/admin_auth.php","probe":"tests/test_cobertura_brvtal_17.py::CoberturaBrvtal17Tests.test_contrato_principal","gap":"Código real `config/admin_auth.php` contrastado con «secure sessions;» por","group":17},{"id":"S42-R003","kind":"A","req":"output escaping;","source":"config/admin_auth.php","probe":"tests/test_cobertura_brvtal_17.py::CoberturaBrvtal17Tests.test_contrato_principal","gap":"Código real `config/admin_auth.php` contrastado con «output escaping;» por","group":17},{"id":"S42-R005","kind":"A","req":"password hashing/verification;","source":"config/admin_password_security.php","probe":"tests/test_cobertura_brvtal_17.py::CoberturaBrvtal17Tests.test_contrato_principal","gap":"Código real `config/admin_password_security.php` contrastado con «password hashing/verification;» por","group":17},{"id":"S42-R006","kind":"A","req":"validated uploads;","source":"api/media-library.php","probe":"tests/test_cobertura_brvtal_17.py::CoberturaBrvtal17Tests.test_contrato_principal","gap":"Código real `api/media-library.php` contrastado con «validated uploads;» por","group":17},{"id":"S42-R007","kind":"A","req":"authorization;","source":"config/admin_auth.php","probe":"tests/test_cobertura_brvtal_17.py::CoberturaBrvtal17Tests.test_contrato_principal","gap":"Código real `config/admin_auth.php` contrastado con «authorization;» por","group":17},{"id":"S42-R008","kind":"A","req":"private logs;","source":"config/admin_auth.php","probe":"tests/test_cobertura_brvtal_17.py::CoberturaBrvtal17Tests.test_contrato_principal","gap":"Código real `config/admin_auth.php` contrastado con «private logs;» por","group":17},{"id":"S42-R009","kind":"A","req":"no public stack traces;","source":"config/admin_auth.php","probe":"tests/test_cobertura_brvtal_17.py::CoberturaBrvtal17Tests.test_contrato_principal","gap":"Código real `config/admin_auth.php` contrastado con «no public stack traces;» por","group":17},{"id":"S42-R010","kind":"A","req":"untrusted input handling.","source":"config/password_rate_limit.php","probe":"tests/test_cobertura_brvtal_18.py::CoberturaBrvtal18Tests.test_contrato_principal","gap":"Código real `config/password_rate_limit.php` contrastado con «untrusted input handling.» por","group":18},{"id":"S42-R011","kind":"C","req":"Never commit real DB passwords, API secrets, encryption keys or private credentials to GitHub.","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_18.py::CoberturaBrvtal18Tests.test_contrato_principal","gap":"«Never commit real DB passwords, API secrets, encryption keys or private credentials t» es una regla, decisión o encabezado documental sin observable atómico en","group":18},{"id":"S43-R001","kind":"A","req":"Future migrations should be explicit, reviewable, data-safe, documented and idempotent where practical.","source":"config/migration_reconcile.php","probe":"tests/test_cobertura_brvtal_18.py::CoberturaBrvtal18Tests.test_contrato_principal","gap":"Código real `config/migration_reconcile.php` contrastado con «Future migrations should be explicit, reviewable, data-safe, documented and idempoten» por","group":18},{"id":"S43-R002","kind":"A","req":"Do not silently mutate production schemas.","source":"config/migration_reconcile.php","probe":"tests/test_cobertura_brvtal_18.py::CoberturaBrvtal18Tests.test_contrato_principal","gap":"Código real `config/migration_reconcile.php` contrastado con «Do not silently mutate production schemas.» por","group":18},{"id":"S43-R003","kind":"A","req":"Source deployment and database migration are independent states.","source":"config/migration_reconcile.php","probe":"tests/test_cobertura_brvtal_18.py::CoberturaBrvtal18Tests.test_contrato_principal","gap":"Código real `config/migration_reconcile.php` contrastado con «Source deployment and database migration are independent states.» por","group":18},{"id":"S43-R004","kind":"B","req":"If new source requires a new production schema, validate the migration first, provide exact SQL when manual production application is required, wait for confirmation, and only then mark the feature fully deployed.","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_18.py::CoberturaBrvtal18Tests.test_contrato_principal","gap":"Falta aplicación segura de SQL, confirmación humana y despliegue secuenciado con recibos reales.","group":18},{"id":"S44-R001","kind":"B","req":"fast routine administration;","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_18.py::CoberturaBrvtal18Tests.test_contrato_principal","gap":"Falta medición de tiempo y facilidad de tareas administrativas en navegador.","group":18},{"id":"S44-R003","kind":"B","req":"visual media selection;","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_18.py::CoberturaBrvtal18Tests.test_contrato_principal","gap":"Falta selección visual de medios con navegador y sesión autenticada.","group":18},{"id":"S44-R004","kind":"B","req":"clear forms;","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_18.py::CoberturaBrvtal18Tests.test_contrato_principal","gap":"Falta evaluación de formularios completos renderizados con datos sintéticos en navegador real.","group":18},{"id":"S44-R005","kind":"B","req":"useful warnings;","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_18.py::CoberturaBrvtal18Tests.test_contrato_principal","gap":"Falta presentación y respuesta real de advertencias en UI autenticada.","group":18},{"id":"S44-R006","kind":"B","req":"consistent navigation;","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_18.py::CoberturaBrvtal18Tests.test_contrato_principal","gap":"Falta navegación integral entre módulos con teclado, sesión y DOM real.","group":18},{"id":"S44-R007","kind":"C","req":"recognizably BRVTAL visual language.","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_18.py::CoberturaBrvtal18Tests.test_contrato_principal","gap":"«recognizably BRVTAL visual language.» es un criterio de producto/documentación sin observable atómico en","group":18},{"id":"S44-R008","kind":"B","req":"Desktop is the primary admin experience, but tablet/mobile must not break.","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_18.py::CoberturaBrvtal18Tests.test_contrato_principal","gap":"Falta prueba responsive en tableta/móvil de menú, formularios y tabla en dispositivos/navegadores.","group":18},{"id":"S44-R009","kind":"B","req":"The canonical sidebar stays visible on desktop.","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_18.py::CoberturaBrvtal18Tests.test_contrato_principal","gap":"Falta observación de sidebar persistente y visible a anchura desktop real.","group":18},{"id":"S44-R010","kind":"C","req":"Do not recreate a parallel admin-sidebar.php architecture for Content Core or Security.","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_18.py::CoberturaBrvtal18Tests.test_contrato_principal","gap":"«Do not recreate a parallel admin-sidebar.php architecture for Content Core or Securit» es una regla, decisión o encabezado documental sin observable atómico en","group":18},{"id":"S44-R011","kind":"C","req":"Operational/technical screens should favor useful visual indicators over walls of raw text; raw diagnostics belong behind deliberate advanced controls.","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_18.py::CoberturaBrvtal18Tests.test_contrato_principal","gap":"«Operational/technical screens should favor useful visual indicators over walls of raw» es una regla, decisión o encabezado documental sin observable atómico en","group":18},{"id":"S45-R001","kind":"B","req":"Sidebar remains persistent while modules replace the central workspace.","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_18.py::CoberturaBrvtal18Tests.test_contrato_principal","gap":"Falta navegación entre módulos que conserve sidebar y sustituya solo workspace central.","group":18},{"id":"S45-R002","kind":"B","req":"Events → Events workspace;","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_18.py::CoberturaBrvtal18Tests.test_contrato_principal","gap":"Falta ruta Events y render del workspace central verificados en sesión de navegador.","group":18},{"id":"S45-R004","kind":"B","req":"Security / 2FA → Security workspace;","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_19.py::CoberturaBrvtal19Tests.test_contrato_principal","gap":"NO_VERIFICABLE: Falta navegación autenticada hasta Security/2FA y contraste del workspace en navegador real; depende de QA de integración con cuenta de prueba autorizada.","group":19},{"id":"S45-R005","kind":"B","req":"System Status → operations/control-room workspace.","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_19.py::CoberturaBrvtal19Tests.test_contrato_principal","gap":"NO_VERIFICABLE: Falta comprobar el render del workspace Operations/Control Room y su ruta en navegador; depende de QA de interfaz.","group":19},{"id":"S46-R001","kind":"B","req":"Maintain contrast, focus states, labels, reasonable keyboard navigation, semantic HTML and reduced-motion support.","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_19.py::CoberturaBrvtal19Tests.test_contrato_principal","gap":"NO_VERIFICABLE: Faltan mediciones reales de contraste, foco visible, etiquetas, teclado y semántica sobre vistas renderizadas; depende de QA de accesibilidad.","group":19},{"id":"S46-R002","kind":"B","req":"Optimize images, scripts, animations, fonts, video, WebGL, lazy loading and caching.","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_19.py::CoberturaBrvtal19Tests.test_contrato_principal","gap":"NO_VERIFICABLE: Faltan métricas de rendimiento y cache de imágenes, fuentes, vídeo, WebGL y scripts en navegador, red y dispositivos; depende de QA de rendimiento.","group":19},{"id":"S46-R003","kind":"A","req":"Avoid heavy effects on devices that do not benefit from them.","source":"js/public-concept05-motion.js","probe":"tests/test_cobertura_brvtal_19.py::CoberturaBrvtal19Tests.test_contrato_principal","gap":"Código real `js/public-concept05-motion.js`; prueba","group":19},{"id":"S46-R004","kind":"A","req":"Admin enhancement assets use deployment-SHA cache busting so browsers receive the code corresponding to the deployed checkout without requiring manual hard-refresh behavior.","source":"config/deployment.php","probe":"tests/test_cobertura_brvtal_19.py::CoberturaBrvtal19Tests.test_contrato_principal","gap":"Código real `config/deployment.php`; prueba","group":19},{"id":"S47-R001","kind":"A","req":"Incomplete content must degrade gracefully.","source":"config/public_home.php","probe":"tests/test_cobertura_brvtal_19.py::CoberturaBrvtal19Tests.test_contrato_principal","gap":"Código real `config/public_home.php`; prueba","group":19},{"id":"S47-R002","kind":"A","req":"No broken images or broken components for artists/events/releases lacking optional media or metadata.","source":"config/media.php","probe":"tests/test_cobertura_brvtal_19.py::CoberturaBrvtal19Tests.test_contrato_principal","gap":"Código real `config/media.php`; prueba","group":19},{"id":"S48-R001","kind":"C","req":"Before creating a new table, endpoint, service, editor, media picker or settings system, check whether an existing implementation can be extended safely.","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_19.py::CoberturaBrvtal19Tests.test_contrato_principal","gap":"NO_VERIFICABLE: La preferencia de extender servicios/editores existentes antes de crear sistemas nuevos es una regla de diseño; requiere revisión de arquitectura por el equipo en cada cambio.","group":19}]''')
SUITES = (
    suite16.CoberturaBrvtal16Tests, suite17.CoberturaBrvtal17Tests,
    suite18.CoberturaBrvtal18Tests, suite19.CoberturaBrvtal19Tests,
)


def classification(identifier: str) -> tuple[str, int]:
    """Use source test classifications; matrix is not authoritative evidence."""
    if identifier in suite16.SCHEMA:
        return "B", 16
    if identifier in suite17.IDS:
        return (suite17.NON_OFFLINE[identifier][0]
                if identifier in suite17.NON_OFFLINE else "A"), 17
    if identifier in suite18.IDS:
        return ("A" if identifier in suite18.A else
                "B" if identifier in suite18.B else "C"), 18
    if identifier in suite19.IDS:
        return ("A" if identifier in suite19.OFFLINE else
                "B" if identifier in suite19.EXTERNAL else "C"), 19
    raise AssertionError("No tracked contract: " + identifier)


def parsed_rows(report: str) -> dict[str, list[str]]:
    part = report.split("## Registro de las 50 filas\n", 1)[1].split("\n## ", 1)[0]
    result = {}
    for line in part.splitlines():
        if not line.startswith("| S"):
            continue
        cells = [c.strip() for c in line.strip().strip("|").split("|")]
        if len(cells) != 7 or cells[0] in result:
            raise AssertionError("Malformed or duplicate row: " + line[:120])
        result[cells[0]] = cells
    return result


class VerificacionBrvtal06Tests(unittest.TestCase):
    def test_50_filas_exactas_sin_promocion(self):
        self.assertEqual(len(CASES), 50)
        self.assertEqual(len({x["id"] for x in CASES}), 50)
        self.assertEqual({k: sum(x["kind"] == k for x in CASES)
                          for k in ("A", "B", "C")}, {"A": 22, "B": 22, "C": 6})
        report = REPORT.read_text(encoding="utf-8")
        actual = parsed_rows(report)
        self.assertEqual(set(actual), {x["id"] for x in CASES})
        for case in CASES:
            with self.subTest(id=case["id"]):
                self.assertEqual(actual[case["id"]], [
                    case["id"], "NO_VERIFICABLE", case["kind"], case["req"],
                    "`" + case["source"] + "`",
                    "`" + case["probe"] + "`", case["gap"],
                ])
                self.assertGreater(len(case["gap"]), 21)
        self.assertIn("0 promociones", report)
        self.assertNotIn("| CUBIERTO |", report)

    def test_fuente_y_clase_contra_contratos_reales(self):
        matrix = MATRIX.read_text(encoding="utf-8")
        for case in CASES:
            with self.subTest(id=case["id"]):
                self.assertEqual(classification(case["id"]),
                                 (case["kind"], case["group"]))
                self.assertTrue((ROOT / case["source"]).is_file())
                self.assertTrue((ROOT / case["probe"].split("::", 1)[0]).is_file())
                self.assertIn("| " + case["id"] + " ·", matrix)
                self.assertIn(case["req"], matrix)

    def test_regresiones_ejecutables_y_negativos(self):
        """Actually run 14 existing PHP/JS-backed tests, not mocked outcomes."""
        suite = unittest.TestSuite()
        for cls in SUITES:
            suite.addTests(unittest.defaultTestLoader.loadTestsFromTestCase(cls))
        output = io.StringIO()
        result = unittest.TextTestRunner(stream=output, verbosity=0).run(suite)
        self.assertEqual(result.testsRun, 14)
        self.assertTrue(result.wasSuccessful(), output.getvalue()[-3500:])

    def test_omisiones_y_promocion_falsa_se_detectan(self):
        content = REPORT.read_text(encoding="utf-8")
        before = parsed_rows(content)
        self.assertEqual(len(before), 50)
        first = CASES[0]["id"]
        changed = content.replace("| " + first + " | NO_VERIFICABLE |",
                                  "| " + first + " | CUBIERTO |", 1)
        self.assertNotEqual(parsed_rows(changed)[first], before[first])
        idx = content.index("| " + first + " |")
        end = content.index("\n", idx)
        missing = content[:idx] + content[end + 1:]
        self.assertEqual(len(parsed_rows(missing)), 49)
        duplicated = content[:end] + "\n" + content[idx:end] + content[end:]
        with self.assertRaises(AssertionError):
            parsed_rows(duplicated)

    def test_solo_sintetico_y_sin_acceso_externo(self):
        source = Path(__file__).read_text(encoding="utf-8")
        forbidden = {"requests", "httpx", "urllib", "socket", "mysql",
                     "pymysql", "psycopg2", "selenium", "playwright"}
        for node in ast.walk(ast.parse(source)):
            if isinstance(node, ast.Import):
                self.assertFalse({n.name.split(".")[0] for n in node.names} & forbidden)
            elif isinstance(node, ast.ImportFrom) and node.module:
                self.assertNotIn(node.module.split(".")[0], forbidden)
        for case in CASES:
            self.assertFalse(Path(case["source"]).is_absolute())
            self.assertNotIn("..", Path(case["source"]).parts)
        self.assertIn("NO_VERIFICABLE", REPORT.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
