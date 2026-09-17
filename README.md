# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md`, `docs/` y los issues de producto.

## Qué se hizo

- Se implementa **#415 / #398 Phase D — MEMORIES**. #416 se cerró como duplicado para mantener una única fuente de alcance.
- Memories siguen siendo assets de la **Media Library**: no se crea una entidad paralela ni otro mini-admin.
- La migration aditiva `media_relations` conecta cada Media con múltiples Events, Artists, Sets y Releases mediante relaciones explícitas y ordenadas.
- El inspector existente de Media Library incorpora **CULTURAL CONTEXT**. Cuando la migration existe, metadata + relaciones se guardan en una sola transacción con auth, CSRF, validación de targets y rollback seguro.
- El source deploy es compatible con una base todavía sin la migration: la Media Library conserva su SAVE legacy y muestra `RELATIONS MIGRATION REQUIRED`; la web pública continúa entregando Media sin relaciones.
- `api/public.php` sigue siendo la fuente pública canónica. Solo entrega relaciones cuyos targets sobreviven los pools públicos finales; IDs y labels draft/private no se filtran al browser.
- Eliminar un Media elimina sus relaciones semánticas por cascade, pero **Cultural Context** permanece separado del reference/usage tracking que protege archivos utilizados.
- Home Memories muestra chips hacia rutas canónicas y añade esos labels al search existente.
- CONNECTED conserva sus cuatro selectores **Artists / Events / Sets / Releases**; Memories aparecen como edges/grupo contextual, no como quinta capa.
- Event Record y páginas Artist/Set/Release pueden mostrar únicamente Memories publicados relacionados explícitamente.
- El runtime core pasa a `app → roster → sets → archive → media → memory-relations`, reutilizando el mismo payload público sin segundo request CMS.
- Se añade cobertura PHP, MariaDB y Playwright para atomicidad, target validation, rollback, cascade, draft/private filtering, migration-pending, mobile/touch y runtime order.
- **La migration NO fue ejecutada en producción.** Aplicar SQL sigue siendo una operación separada y explícita.

## Archivos modificados en este deploy

**Diff funcional:** `19 archivos` · **+1225** líneas · **−22** líneas *(sin contar README, porque este snapshot modifica su propio diff al actualizarse).*  
Leyenda: 🟢 nuevo · 🟡 modificado · `+ / −` líneas frente al `main` base de este deploy.

### DATA / ADMIN

- `database/migration_media_relations_01.sql` — 🟢 NEW · **+13 / −0** · tabla aditiva many-to-many Media → Event/Artist/Set/Release con cascade al borrar Media.
- `api/media-relations.php` — 🟢 NEW · **+177 / −0** · normalización, validación estricta, locking de targets, catálogo admin y reemplazo transaccional de relaciones.
- `api/media-context.php` — 🟢 NEW · **+128 / −0** · endpoint protegido GET/PUT para contexto cultural y guardado atómico de metadata + relaciones.
- `discadmin/media-relations.js` — 🟢 NEW · **+171 / −0** · editor CULTURAL CONTEXT dentro del inspector existente y fallback seguro si la migration falta.
- `discadmin/media-relations.css` — 🟢 NEW · **+1 / −0** · presentación responsive, focus y targets táctiles del editor.
- `discadmin/index.php` — 🟡 MOD · **+4 / −2** · carga la extensión dentro del shell canónico.

### PUBLIC ARCHIVE

- `api/public-memory-relations.php` — 🟢 NEW · **+161 / −0** · sanitización contra pools públicos, Memory edges y consulta de Memories publicados por entidad.
- `api/public.php` — 🟡 MOD · **+15 / −2** · adjunta relaciones públicas sanitizadas y extiende el grafo cultural sin endpoint paralelo.
- `js/public-memory-relations.js` — 🟢 NEW · **+119 / −0** · chips de contexto en Media y grupo MEMORIES dentro de detalles CONNECTED usando el payload compartido.
- `css/public-memory-relations.css` — 🟢 NEW · **+1 / −0** · tratamiento visual/focus/mobile de context chips y Memory detail rows.
- `js/public-runtime-loader.js` — 🟡 MOD · **+2 / −2** · incorpora `memory-relations` al runtime core versionado después de Media.
- `index.php` — 🟡 MOD · **+10 / −2** · añade Memories explícitos a páginas canónicas y entrega CSS público de Phase D.

### TESTS / CONTEXTO

- `tests/media-relations-contract.php` — 🟢 NEW · **+83 / −0** · contratos de schema, auth/CSRF, atomicidad, privacidad, graph y runtime.
- `tests/integration/media-relations.php` — 🟢 NEW · **+136 / −0** · MariaDB real: migration, de-dupe, locking, rollback, privacidad, draft Media y cascade.
- `tests/e2e/discadmin-media-relations.spec.mjs` — 🟢 NEW · **+104 / −0** · inspector real, PUT combinado, fallback pre-migration y mobile.
- `tests/e2e/public-memories-phase-d.spec.mjs` — 🟢 NEW · **+80 / −0** · chips canónicos, escaping, search context, CONNECTED Memories y mobile.
- `tests/e2e/public-mobile-performance.spec.mjs` — 🟡 MOD · **+6 / −3** · exige el nuevo módulo core en el orden/versionado correcto.
- `package.json` — 🟡 MOD · **+1 / −1** · conecta la integración MariaDB al pipeline canónico.
- `AGENTS.md` — 🟡 MOD · **+13 / −10** · registra la arquitectura durable de Memories, privacidad y runtime.

### SNAPSHOT

- `README.md` — 🟡 MOD · **AUTO** · este snapshot operativo del deploy.

## Validación

- Base exacta: `main` `c262a8af5d1df0e520bdb1ad4d297df9cf206fa6`.
- Esa base quedó completamente verde en **BRVTAL CI #655** después del merge de #412.
- Scope canónico: issue **#415** · parent **#398 Phase D** · rama `design/public-memories-phase-d`; #416 cerrado como duplicado.
- No se ejecutó SQL de producción, no se añadió token/credencial y no se creó una segunda API pública.
- Pendiente: abrir PR, ejecutar BRVTAL CI + CodeRabbit, corregir findings válidos, squash merge y validar el SHA exacto de `main`.
- CI verde significará **VALIDATED IN CODE**, no **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Validar Phase D con BRVTAL CI + CodeRabbit y cerrar #415 por squash.
2. Verificar el CI del SHA exacto resultante en `main`.
3. Mantener la migration de producción como paso explícito separado; no aplicarla automáticamente.
4. Continuar #398 con **Phase E / polish**, preservando la arquitectura cultural conectada.
5. Repetir performance mobile + desktop sobre el deploy exacto cuando los cambios públicos estén realmente desplegados.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Visión pública: `#398`.
- Phase D / Memories: `#415`.
- Event Record: `#404` / PR `#405`.
- Roster: `#407` / PR `#408`.
- Sets listening library: `#411` / PR `#412`.
- Estrategia de validación: `docs/TESTING.md`.
