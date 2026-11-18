# BRVTAL #1601 — verificación conservadora lote 09/09

**Resultado:** ocho filas contrastadas con testigo exacto o limitación externa verificable, sin promover ninguna a CUBIERTO. El cambio solo agrega pruebas y evidencia documental; la hoja de consolidación conserva la autoridad sobre docs/matriz-cobertura.md.

## Método

Fuentes: docs/BRVTAL-SPEC.md secciones 40, 42, 44, 51; testigos ya fusionados de #1285/PR #1320, #1286/PR #1321, #1287/PR #1322 y #1288/PR #1324. El nuevo test invoca la lógica PHP versionada de detección de tablas y control CSRF, con DDL y PDO/sesión sintéticos. La presencia en código no demuestra tablas migradas en Hostinger, recorridos interactivos ni autorización de uso real.

Clases: **A** subcontrato offline real, **B** recibo externo todavía ausente, **C** obligación declarativa que no equivale a funcionalidad.

## Filas verificadas

| ID | Estado | Clase | Fuente | Testigo exacto | Resultado y verificación todavía necesaria |
| --- | --- | --- | --- | --- | --- |
| S40-L627 | NO_VERIFICABLE | B | database/schema.sql + config/schema_catalog.php | tests/test_verificacion_brvtal_09.py::test_schema_real_php | sets_media: DDL y helper real ejercidos con PDO sintético. Falta constatar tabla en MariaDB productiva y recibo de migración. |
| S40-L628 | NO_VERIFICABLE | B | database/schema.sql + config/schema_catalog.php | tests/test_verificacion_brvtal_09.py::test_schema_real_php | media: contrato de tabla y prueba negativa reproducibles. No acredita el esquema realmente desplegado en el host. |
| S40-L629 | NO_VERIFICABLE | B | database/schema.sql + config/schema_catalog.php | tests/test_verificacion_brvtal_09.py::test_schema_real_php | pages: presencia versionada y consulta falsa verificadas. Falta observación de esquema real mediante operación autorizada. |
| S40-L630 | NO_VERIFICABLE | B | database/schema.sql + config/schema_catalog.php | tests/test_verificacion_brvtal_09.py::test_schema_real_php | settings: detección positiva y negativa sobre fake PDO. Falta evidencia de base productiva y despliegue migratorio. |
| S42-L662 | NO_VERIFICABLE | A | config/admin_auth.php | tests/test_verificacion_brvtal_09.py::test_csrf_real_php | CSRF en PHP real: token de 64 caracteres aceptado y token forjado rechazado. Falta reconciliar el PR #1321 en el guard de evidencias de la matriz; no acredita una sesión productiva. |
| S44-L686 | NO_VERIFICABLE | B | docs/BRVTAL-SPEC.md sec44 | tests/test_verificacion_brvtal_09.py::test_normative_limits | few clicks es exigencia UX textual. Falta medir clics y usabilidad en navegador autenticado de forma reproducible. |
| S44-L691 | NO_VERIFICABLE | B | docs/BRVTAL-SPEC.md sec44 | tests/test_verificacion_brvtal_09.py::test_normative_limits | drafts es exigencia UX textual. Falta persistir, cerrar y recuperar borradores en una sesión y BD autorizadas. |
| S51-L754 | NO_VERIFICABLE | C | docs/BRVTAL-SPEC.md sec51 | tests/test_verificacion_brvtal_09.py::test_normative_limits | phases es una entrada declarativa del roadmap #533. Requiere revisión editorial, no un test ficticio de funcionalidad. |

## Aceptación, límites y reversión

AC-01: 8/8 filas tienen origen, testigo y límite específico. AC-02: las aserciones usan código real y fixtures sintéticos; los negativos fallan si se elimina una tabla, si se acepta CSRF forjado o si desaparece una obligación textual. AC-03: solo el check factory-ci / Validar terminal sobre el HEAD definitivo lo acredita.

No se modifica el esquema, el producto, el workflow, las políticas o la matriz. Este PR **no autoriza** acceso a datos reales, migraciones, go-live, despliegue, publicar evidencia falsa de producción ni reclasificar filas a CUBIERTO. Reversión: revertir el squash de estos dos archivos.
