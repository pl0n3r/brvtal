# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md`, `docs/` y los issues de producto.

## Qué se hizo

- Se implementa **#411 / #398 Phase C — SOUND** para convertir Home Sets en una biblioteca de escucha conectada, no una lista plana de enlaces externos.
- Los Sets publicados siguen usando `api/public.php` como fuente canónica y reutilizan `window.BRVTALPublicDataPromise`; no se añade una segunda llamada CMS ni endpoint paralelo.
- La biblioteca ofrece vistas **LATEST / ARTIST / EVENT** utilizando únicamente relaciones estructuradas reales de `sets_media`.
- Cada Set navega primero a su registro canónico `/sets/{slug}` y conserva un CTA secundario **LISTEN ↗** hacia la plataforma externa cuando existe una URL HTTP(S) válida.
- Set → Artist y Set → Event se sanitizan server-side contra los pools públicos finales. Relaciones hacia entidades privadas/draft conservan campos explícitos en `null`, sin id, nombre/título ni slug utilizable en el navegador.
- La UI expone contexto Artist/Event solo cuando la relación pública existe; los Sets sin relaciones aparecen como **INDEPENDENT RECORD**.
- No se infieren géneros desde títulos/descripciones y no se crea filtro de género porque el modelo actual no tiene metadata estructurada que lo respalde.
- Un payload público válido con `sets=[]` reemplaza el fallback estático por un empty state real; un fallo/rechazo del request compartido conserva el fallback existente.
- `hidden` domina explícitamente sobre los layouts flex/grid de los controles, de modo que el empty state no deja modos de descubrimiento visibles.
- El runtime público pasa a `app → roster → sets → archive → media`, manteniendo versionado, degradación resiliente y arquitectura coarse-pointer/reduced-motion.
- La capa visual conserva el lenguaje brutalista del sitio, focus visible, targets táctiles de al menos ~44 px y layout sin overflow en 390 px.
- `AGENTS.md` registra como regla durable que Sets discovery es relationship-driven y que Memories/event connections futuras solo deben existir con relaciones estructuradas explícitas.

## Archivos modificados en este deploy

**Diff funcional:** `9 archivos` · **+478** líneas · **−9** líneas *(sin contar README, porque este snapshot modifica su propio diff al actualizarse).*  
Leyenda: 🟢 nuevo · 🟡 modificado · `+ / −` líneas frente al `main` base de este deploy.

### PUBLIC SETS / DELIVERY

- `api/public-related.php` — 🟡 MOD · **+18 / −4** · sanitiza relaciones Set → Artist/Event contra entidades públicas y añade nombres/slugs canónicos sin filtrar referencias privadas.
- `index.php` — 🟡 MOD · **+8 / −1** · convierte el framing estático de Sets a SOUND LIBRARY, elimina género hard-coded y entrega el CSS específico.
- `js/public-runtime-loader.js` — 🟡 MOD · **+1 / −1** · incorpora Sets library al runtime core versionado después de Roster.
- `js/public-sets-library.js` — 🟢 NEW · **+207 / −0** · render conectado, filtros LATEST/ARTIST/EVENT, navegación canónica, LISTEN externo, empty/failure states y reutilización del request público compartido.

### VISUAL

- `css/public-sets-library.css` — 🟢 NEW · **+43 / −0** · controles/listado brutalistas, hidden-state efectivo, focus/touch targets, relaciones, empty state y protección mobile contra overflow.

### TESTS

- `tests/public-sets-library-contract.php` — 🟢 NEW · **+56 / −0** · privacidad de relaciones, nulls explícitos, canonical routes, ausencia de género inventado y no duplicación del request público.
- `tests/e2e/public-sets-library-phase-c.spec.mjs` — 🟢 NEW · **+137 / −0** · ejecuta el módulo real con filtros, enlaces/CTA, relaciones, mobile, payload vacío válido y fallback ante fallo.
- `tests/e2e/public-mobile-performance.spec.mjs` — 🟡 MOD · **+4 / −1** · exige Sets library como módulo core versionado sin degradar el runtime adaptativo.

### CONTEXTO DURABLE

- `AGENTS.md` — 🟡 MOD · **+4 / −2** · registra Sets listening library, nuevo orden del runtime y la regla de no inferir taxonomías/relaciones inexistentes.

### SNAPSHOT

- `README.md` — 🟡 MOD · **AUTO** · este snapshot operativo del deploy.

## Validación

- Base exacta: `main` `8cc3b877cd61453e1743d25dd4df53c679f13e67`.
- Esa base quedó verde en **BRVTAL CI #649** después del squash de #410.
- Scope: issue **#411** · PR **#412** · parent **#398 Phase C** · rama `design/public-sets-library-phase-c`.
- No hay migration, tabla, nuevo endpoint público, player pesado, autoplay ni cambio de i18n.
- La API pública sigue siendo la autoridad y sanitiza las relaciones antes de entregarlas al browser.
- BRVTAL CI **#650** detectó una aserción incorrecta en el contrato nuevo: PHP `??` sustituía también valores `null`. El test se corrigió para exigir `array_key_exists(...)` y valor estrictamente `null`; la lógica productiva no necesitó cambios.
- Run intermedio **#651** dejó PHP, MariaDB y real-stack funcionalmente verdes, y Chromium detectó que `.sets-library-modes { display:grid/flex }` mantenía visibles controles con atributo `hidden`. Se corrigió la cascada CSS para que `[hidden]` sea autoritativo.
- Pendiente: BRVTAL CI + CodeRabbit sobre este head final; squash únicamente con `validate` verde y revisión limpia.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobación real del deploy.

## Qué sigue

1. Validar el head final de #412 con BRVTAL CI + CodeRabbit.
2. Squash merge solo con gates verdes y revisión limpia.
3. Verificar BRVTAL CI del SHA exacto resultante en `main`.
4. Continuar #398 profundizando Archive/Memories únicamente con relaciones estructuradas reales.
5. Repetir métricas de performance sobre el deploy exacto después del merge público; métricas anteriores quedan como baseline histórico.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Visión pública: `#398`.
- Sets listening library: `#411`.
- Event Record: `#404` / PR `#405`.
- Roster público: `#407` / PR `#408`.
- Estrategia de validación: `docs/TESTING.md`.
