# QA integrada: Event → Artist → Lineup → Set

**Issue:** [BRVTAL #1696](https://github.com/pl0n3r/brvtal/issues/1696) · **Dependencia:** #1692 completada.
**Fuente de verdad:** `tests/e2e/discadmin-integral-event-artist-set.spec.mjs`.
**Base inspeccionada:** `main@481d7af35579a7ee87b53c29ec4218b4e2b62f4d`.
**Evidencia:** el PR y sus checks anclados al HEAD exacto; SHA definitiva de squash se registra tras la fusión. Este documento no declara CI verde ni producción validada.

## Recorrido y contratos

- **UI:** shell DISCADMIN único, `openLineup(eventId)`, buscador de artistas y botón Guardar, luego `openModal('sets', id)` con selector real de artistas y evento.
- **API real:** `POST /api/index.php/events` en draft; `POST /api/index.php/artists` disparado por Enter en el lineup; `POST/GET /api/index.php/events/{id}/lineup`; `POST/GET /api/index.php/sets`, con sesión admin y header `X-CSRF-Token`.
- **DB real y descartable:** MariaDB `brvtal_test_ci` inicializada por `tests/e2e/run-content-core-real-stack.sh`. Tablas `events`, `artists`, `event_artists`, `sets_media` y `set_artists`, con lectura SQL de claves de relación desde el proceso de test.
- **Vuelta a UI:** recargar el admin, abrir de nuevo el lineup y comprobar rol/orden; abrir editor real del Set y confirmar el mismo artista/entidad Event.
- **Alcance de esta prueba:** el Set se crea en el API autenticado y se relee en el editor real; no se replica la navegación del shell ni se reimplementa una API en un mock. Solo el error de transporte y el test aislado de layout usan intercepción sintética.

## Matriz AC → recorrido → evidencia

| Criterio | UI → API → DB → UI | Positivo y negativo | Job |
|---|---|---|---|
| AC-01 | Dos Events draft; Enter crea Artist externo; Guardar lineup; recargar; crear Set asociado; leer joins SQL y reabrir editor | `event_artists` y `set_artists` reales, rol/orden y asociación persistentes | `real-stack` |
| AC-02 | POST sin sesión, POST con sesión sin CSRF, fallo de red simulado en otro POST artista, slug duplicado real | Sin sesión: 401/403; sin CSRF: 419 `CSRF`; 409 `DUPLICATE_SLUG` vía UI, roles y selección conservados, segundo evento sin lineup | `real-stack` |
| AC-02 | Artista draft, ambos Events draft, Set draft e inspección API pública y SQL | `is_collective_member=0` y nombres de ambos Events, artista y Set ausentes del API público | `real-stack` |
| AC-03 | Código de modal actual en harness de navegador; Enter con foco; Guardar a 390/1440 | Editor y acción Guardar visibles dentro del viewport, escritura visual por teclado | `chromium` |
| AC-04 | Ejecución terminal de tests, SonarCloud y revisión FINAL de CodeRabbit | CI y seguridad en HEAD exacto antes de squash, sin equiparar CI y despliegue | `validate` |

## Fixture y seguridad

1. El runner crea y destruye únicamente una base con prefijo `brvtal_test`, instala migraciones reales y levanta PHP en localhost con usuarios CI sintéticos. Se prohíbe usar cuentas o bases de producción.
2. Cada tamaño usa slugs únicos por timestamp/worker: Event principal con artista de fixture y artista externo recién creado, segundo Event sin lineup y un Set draft vinculado.
3. El test rechaza consultar SQL si el nombre de base no cumple el prefijo de prueba. Todos los IDs se reciben del API autenticado y convierten a número.
4. El `finally` borra Set, ambos Events y Artist y el runner descarta toda la base. No se crean pagos, credenciales reales ni migraciones productivas.
5. Los negativos de auth/CSRF y slug duplicado son respuestas del PHP real. Solo se inyecta una caída de red en el intento negativo de POST artista; el positivo y su comprobación SQL se realizan contra MariaDB auténtica.

## Cómo reproducir y límites de evidencia

La suite `discadmin-integral-event-artist-set.spec.mjs` se ejecuta dentro de `tests/e2e/run-content-core-real-stack.sh`, que exporta `BRVTAL_REAL_STACK_URL`, credenciales de prueba y `BRVTAL_TEST_DB_NAME`. Como el runner usa una lista cerrada de archivos, agregar la suite a esa lista requiere ampliar oficialmente los claims V3. El job `chromium` común ejecuta el test de layout sin base. El test real-stack **solo se registra** cuando existe el servidor descartable, no utiliza `test.skip` ni afirma haber persistido sin DB.

**Separación de estados:** IMPLEMENTADO ≠ CI VALIDADO ≠ PR FUSIONADO ≠ DESPLEGADO ≠ COMPROBADO EN PRODUCCIÓN. Ninguno de esos últimos estados se deduce de este documento.
