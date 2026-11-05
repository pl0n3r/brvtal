# BRVTAL — Verificación 08/09 · Issue #1600

**Corte:** `main@e723928dc42d829970b19084b970d957d46b2c9e`, 5 de noviembre de 2026. **Alcance exacto:** 50 filas del lote 08 de `docs/matriz-cobertura.md`. Esta hoja contrasta evidencia **offline** y no altera el estado `NO_VERIFICABLE` de la matriz; su actualización es responsabilidad de la hoja consolidadora. No se publica, despliega ni accede a Hostinger, base de datos productiva o proveedores.

## Método y estados

- `A_LOCAL`: 20 filas: un archivo real aporta un subcontrato parcialmente ejecutable, contrastable con tests existentes. **No** acredita funcionamiento end-to-end, producción ni promoción a `CUBIERTO`.
- `B_EXTERNA`: 23 filas: la afirmación completa depende de navegador, sesión, publicación, configuración, base de datos o prueba de despliegue **autenticada**. El código/fixture disponible no equivale a ese recibo.
- `C_DECLARATIVA`: 2 filas: son destinos u obligaciones editoriales, no un comportamiento local aislado demostrable.
- `SUPERSEDED`: 5 filas: texto histórico del catálogo de auditoría sustituido en el SPEC vigente; no reintroducir características obsoletas para hacer pasar una aserción.
- **`UNKNOWN`** para todos los resultados reales. `NO_VERIFICABLE` se mantiene hasta que la hoja consolidada compruebe evidencia primaria externa y un PR efectivamente fusionado.

## Libro de 50 comprobaciones

| ID | Dictamen | Afirmación exacta en matriz | Fuente de contraste | Ancla actual exacta | Prueba anterior en matriz | Presencia de prueba | Seguimiento |
| --- | --- | --- | --- | --- | --- | --- | --- |
| S39B-R013 | A_LOCAL | Artists, Sets, Releases and Blog keep visual ordering; drag/drop ordering is disabled whenever an explicit grid sort or partial filtered view is active; | `discadmin/admin-data-grid.js` | `Artists, Sets, Releases and Blog keep visual ordering; drag/drop ordering is disabled whenever an explicit grid sort or partial filtered view is active;` | `tests/test_cobertura_brvtal_16.py` | EXISTE | #1285 |
| S39B-R014 | A_LOCAL | Releases Catalog and Blog render through the same canonical grid pattern as the core record lists; | `discadmin/admin-data-grid.js` | `Releases Catalog and Blog render through the same canonical grid pattern as the core record lists;` | `tests/test_cobertura_brvtal_16.py` | EXISTE | #1285 |
| S39B-R015 | A_LOCAL | Media keeps its uploader and inspector around the canonical record table rather than maintaining a separate record-card interaction model. | `discadmin/admin-data-grid.js` | `Media keeps its uploader and inspector around the canonical record table rather than maintaining a separate record-card interaction model.` | `tests/test_cobertura_brvtal_16.py` | EXISTE | #1285 |
| S39B-R016 | A_LOCAL | The shared grid must remain keyboard/focus accessible and horizontally usable on narrow/mobile viewports. | `discadmin/admin-data-grid.js` | `The shared grid must remain keyboard/focus accessible and horizontally usable on narrow/mobile viewports.` | `tests/test_cobertura_brvtal_16.py` | EXISTE | #1285 |
| H212-01 | SUPERSEDED | Traducción ES/EN: arquitectura provider-neutral; DeepL REAL requiere sustituir decisión OWNER B y resolver privacidad/proveedor. | `docs/BRVTAL-SPEC.md` | `**Owner decision (2026-11-04, #1440): BRVTAL's public website is Spanish-only` | `tests/test_cobertura_brvtal_01.py` | EXISTE | #212 |
| S03-L41 | A_LOCAL | Lenis; | `js/menu-scroll-lock.js` | `Lenis;` | `tests/test_cobertura_brvtal_01.py` | EXISTE | #1270 |
| S07-L74 | B_EXTERNA | Home; | `docs/BRVTAL-SPEC.md` | `Home;` | `tests/test_cobertura_brvtal_02.py` | AUSENTE/NO APLICABLE | #1271 |
| S07-L75 | B_EXTERNA | About; | `docs/BRVTAL-SPEC.md` | `About;` | `tests/test_cobertura_brvtal_02.py` | AUSENTE/NO APLICABLE | #1271 |
| S07-L76 | B_EXTERNA | Manifesto; | `docs/BRVTAL-SPEC.md` | `Manifesto;` | `tests/test_cobertura_brvtal_02.py` | AUSENTE/NO APLICABLE | #1271 |
| S07-L77 | B_EXTERNA | Events; | `docs/BRVTAL-SPEC.md` | `Events;` | `tests/test_cobertura_brvtal_02.py` | AUSENTE/NO APLICABLE | #1271 |
| S07-L78 | B_EXTERNA | Artists; | `docs/BRVTAL-SPEC.md` | `Artists;` | `tests/test_cobertura_brvtal_02.py` | AUSENTE/NO APLICABLE | #1271 |
| S07-L80 | B_EXTERNA | Releases; | `docs/BRVTAL-SPEC.md` | `Releases;` | `tests/test_cobertura_brvtal_02.py` | AUSENTE/NO APLICABLE | #1271 |
| S07-L82 | B_EXTERNA | Contact; | `docs/BRVTAL-SPEC.md` | `Contact;` | `tests/test_cobertura_brvtal_02.py` | AUSENTE/NO APLICABLE | #1271 |
| S07-L83 | B_EXTERNA | Legal; | `docs/BRVTAL-SPEC.md` | `Legal;` | `tests/test_cobertura_brvtal_02.py` | AUSENTE/NO APLICABLE | #1271 |
| S07-L84 | B_EXTERNA | Blog; | `docs/BRVTAL-SPEC.md` | `Blog;` | `tests/test_cobertura_brvtal_02.py` | AUSENTE/NO APLICABLE | #1271 |
| S09-L105 | A_LOCAL | drafts; | `api/event-workflow-lib.php` | `drafts;` | `tests/test_cobertura_brvtal_03.py` | EXISTE | #1272 |
| S09-L106 | A_LOCAL | publish; | `api/event-workflow-lib.php` | `publish;` | `tests/test_cobertura_brvtal_03.py` | EXISTE | #1272 |
| S09-L107 | A_LOCAL | archive; | `api/event-workflow-lib.php` | `archive;` | `tests/test_cobertura_brvtal_03.py` | EXISTE | #1272 |
| S09-L108 | B_EXTERNA | preview; | `docs/BRVTAL-SPEC.md` | `preview;` | `tests/test_cobertura_brvtal_03.py` | EXISTE | #1272 |
| S09-L109 | A_LOCAL | media; | `api/event-workflow-lib.php` | `media;` | `tests/test_cobertura_brvtal_03.py` | EXISTE | #1272 |
| S09-L110 | A_LOCAL | SEO; | `api/event-workflow-lib.php` | `SEO;` | `tests/test_cobertura_brvtal_03.py` | EXISTE | #1272 |
| S09-L111 | B_EXTERNA | statistics; | `docs/BRVTAL-SPEC.md` | `statistics;` | `tests/test_cobertura_brvtal_03.py` | EXISTE | #1272 |
| S09-L112 | A_LOCAL | tickets; | `api/event-workflow-lib.php` | `tickets;` | `tests/test_cobertura_brvtal_03.py` | EXISTE | #1272 |
| S09-L119 | A_LOCAL | draft; | `api/event-workflow-lib.php` | `draft;` | `tests/test_cobertura_brvtal_03.py` | EXISTE | #1272 |
| S09-L120 | A_LOCAL | published; | `api/event-workflow-lib.php` | `published;` | `tests/test_cobertura_brvtal_03.py` | EXISTE | #1272 |
| S09-L121 | A_LOCAL | archived; | `api/event-workflow-lib.php` | `archived;` | `tests/test_cobertura_brvtal_03.py` | EXISTE | #1272 |
| S09-L122 | A_LOCAL | upcoming; | `api/event-workflow-lib.php` | `upcoming;` | `tests/test_cobertura_brvtal_04.py` | EXISTE | #1273 |
| S09-L125 | A_LOCAL | sold_out; | `api/event-workflow-lib.php` | `sold_out;` | `tests/test_cobertura_brvtal_04.py` | EXISTE | #1273 |
| S09-L126 | A_LOCAL | cancelled; | `api/event-workflow-lib.php` | `cancelled;` | `tests/test_cobertura_brvtal_04.py` | EXISTE | #1273 |
| S09-L127 | A_LOCAL | finished. | `api/event-workflow-lib.php` | `finished.` | `tests/test_cobertura_brvtal_04.py` | EXISTE | #1273 |
| S13-L180 | C_DECLARATIVA | Sets; | `docs/BRVTAL-SPEC.md` | `Sets;` | `tests/test_cobertura_brvtal_04.py` | EXISTE | #1273 |
| S13-L181 | C_DECLARATIVA | Releases. | `docs/BRVTAL-SPEC.md` | `Releases.` | `tests/test_cobertura_brvtal_04.py` | EXISTE | #1273 |
| S24-L368 | B_EXTERNA | one shell; | `docs/BRVTAL-SPEC.md` | `one shell;` | `tests/test_cobertura_brvtal_07.py` | EXISTE | #1276 |
| S24-L375 | SUPERSEDED | Dashboard; | `docs/BRVTAL-SPEC.md` | `1. Dashboard` | `tests/test_cobertura_brvtal_07.py` | EXISTE | #1276 |
| S24-L376 | B_EXTERNA | Events; | `docs/BRVTAL-SPEC.md` | `Events;` | `tests/test_cobertura_brvtal_07.py` | EXISTE | #1276 |
| S24-L377 | B_EXTERNA | Artists; | `docs/BRVTAL-SPEC.md` | `Artists;` | `tests/test_cobertura_brvtal_07.py` | EXISTE | #1276 |
| S24-L378 | B_EXTERNA | Releases; | `docs/BRVTAL-SPEC.md` | `Releases;` | `tests/test_cobertura_brvtal_07.py` | EXISTE | #1276 |
| S24-L379 | B_EXTERNA | Sets; | `docs/BRVTAL-SPEC.md` | `Sets;` | `tests/test_cobertura_brvtal_07.py` | EXISTE | #1276 |
| S24-L380 | SUPERSEDED | Media; | `docs/BRVTAL-SPEC.md` | `4. Media` | `tests/test_cobertura_brvtal_07.py` | EXISTE | #1276 |
| S24-L381 | SUPERSEDED | Pages; | `docs/BRVTAL-SPEC.md` | `2. Pages` | `tests/test_cobertura_brvtal_07.py` | EXISTE | #1276 |
| S24-L382 | B_EXTERNA | Blog; | `docs/BRVTAL-SPEC.md` | `Blog;` | `tests/test_cobertura_brvtal_07.py` | EXISTE | #1276 |
| S24-L385 | SUPERSEDED | Settings; | `docs/BRVTAL-SPEC.md` | `11. Settings` | `tests/test_cobertura_brvtal_08.py` | EXISTE | #1277 |
| S26-L427 | A_LOCAL | CSRF; | `config/admin_auth.php` | `CSRF;` | `tests/test_cobertura_brvtal_08.py` | EXISTE | #1277 |
| S38-L592 | A_LOCAL | PHP syntax; | `config/version.php` | `PHP syntax;` | `tests/test_cobertura_brvtal_14.py` | EXISTE | #1283 |
| S38-L597 | B_EXTERNA | config; | `docs/BRVTAL-SPEC.md` | `config;` | `tests/test_cobertura_brvtal_14.py` | EXISTE | #1283 |
| S38-L598 | B_EXTERNA | secrets; | `docs/BRVTAL-SPEC.md` | `secrets;` | `tests/test_cobertura_brvtal_14.py` | EXISTE | #1283 |
| S38-L599 | B_EXTERNA | integrity; | `docs/BRVTAL-SPEC.md` | `integrity;` | `tests/test_cobertura_brvtal_14.py` | EXISTE | #1283 |
| S40-L623 | B_EXTERNA | `admins`; | `docs/BRVTAL-SPEC.md` | `'admins';` | `tests/test_cobertura_brvtal_16.py` | EXISTE | #1285 |
| S40-L624 | B_EXTERNA | `events`; | `docs/BRVTAL-SPEC.md` | `'events';` | `tests/test_cobertura_brvtal_16.py` | EXISTE | #1285 |
| S40-L625 | B_EXTERNA | `artists`; | `docs/BRVTAL-SPEC.md` | `'artists';` | `tests/test_cobertura_brvtal_16.py` | EXISTE | #1285 |

## Contradicciones y referencias rotas

La traducción `H212-01` no debe desarrollarse a partir del texto anterior: #1440 y `docs/BRVTAL-SPEC.md` fijan **español único**, sin DeepL ni integración real de proveedor, y el histórico #212 fue cerrado como no planificado. Los destinos `S24-L375`, `S24-L380`, `S24-L381` y `S24-L385` se contrastan contra los actuales renglones numerados `1. Dashboard`, `4. Media`, `2. Pages` y `11. Settings`, respectivamente; no se presume que la ausencia del texto con punto y coma sea la ausencia del módulo.

Las nueve filas `S07-L74` a `S07-L84` de esta tanda hacen referencia al antiguo `tests/test_cobertura_brvtal_02.py`, **ausente del árbol actual**. El catálogo conserva la referencia como brecha documentada de trazabilidad, sin fabricar test, deploy ni cumplimiento. La validación HTTP de estas rutas debe consumir un recibo E2E autenticado en hoja compatible; la matriz sigue pendiente de consolidación.

La fila `S07-L80` (Releases público) conserva evidencia externa pendiente; [PR #1603](https://github.com/pl0n3r/brvtal/pull/1603) continúa separado y es propietario del cambio de `/releases`, no parte del claim de esta hoja. Los estados de eventos, esquema y grid cuentan con pruebas **sintéticas** citadas, pero la existencia de su fuente no equivale al estado real de una migración, sesión, monitor o navegador.

## Prueba, falsaciones y limitaciones

Ejecutar `python3 -m unittest -v tests.test_verificacion_brvtal_08` en un checkout real con Python y las dependencias del proyecto; además ejecutar las pruebas heredadas de grid/eventos/seguridad si el entorno contiene Node y PHP. La regresión falla cerrado ante IDs omitidos/duplicados, cambio de obligación, promoción falsa, fuente ausente, referencia degradada, o reintroducción de la política ES/EN. No modifica `docs/matriz-cobertura.md`, `AGENTS.md`, `docs/BRVTAL-SPEC.md` ni permisos de producción.

**Resultado que puede declarar esta hoja:** inventario reproducible de 50 comprobaciones con evidencias locales o brechas delimitadas. **Resultado que no puede declarar:** todas las rutas desplegadas, esquemas migrados, `VALIDATED_IN_PRODUCTION`, autorización de proveedor, cobertura integral o cierre automático de pruebas externas. Reversión: revert de los dos archivos documentales/de prueba del squash.
