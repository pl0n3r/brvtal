# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md`, `docs/` y los issues de producto.

## Qué se hizo

- Se implementa **#398 / Phase C — Roster** mediante el issue **#407** sobre el lifecycle de Artists ya saneado por #406.
- El bloque público de Artists pasa de catálogo genérico a **BRVTAL Roster**, agrupando registros publicados como `CORE / ACTIVE`, `ALUMNI / ARCHIVE` y `ARTISTS / COLLABORATORS` sin crear un nuevo valor de membership en backend.
- El orden usa `collective_order` para miembros/alumni, conserva `sort_order` como fallback estable y finalmente nombre.
- Cada Artist con slug navega a su URL canónica `/artists/{slug}`; el Roster deja de usar Website/Instagram como destino principal.
- La bio deja de mostrarse como si fuera un género musical. El metadata de cada fila describe únicamente estado real o contexto público.
- El runtime de Roster reutiliza `window.BRVTALPublicDataPromise`; **no hace una segunda petición** a `/api/public.php`.
- La ficha canónica Artist conserva el renderer público existente y se enriquece en servidor con estado/membership real, próximos Events, `PAST NIGHTS`, Sets, Releases y `TRANSMISSIONS` relacionadas explícitamente mediante `blog_post_relations`.
- Los Events relacionados se separan usando la política canónica `brvtal_public_event_is_historical(...)`, no lógica paralela de fechas.
- Los posts editoriales solo aparecen si están `published` y relacionados explícitamente con el Artist.
- Memories y géneros **no se infieren** porque todavía no existe metadata/relación estructurada que los respalde.
- La capa visual conserva negro/rojo, grano/scanlines y usa verde ácido como señal controlada para miembros activos; mobile elimina el preview flotante, preserva targets táctiles y evita overflow.
- No se añadió tabla, migration ni API paralela. `api/public.php` sigue siendo la fuente pública canónica.

## Archivos modificados en este deploy

**Diff funcional:** `7 archivos` · **+461** líneas · **−2** líneas *(sin contar README, porque este snapshot modifica su propio diff al actualizarse).*  
Leyenda: 🟢 nuevo · 🟡 modificado · `+ / −` líneas frente al `main` base de este deploy.

### PUBLIC ROSTER / DELIVERY

- `config/public_artist.php` — 🟢 NEW · **+118 / −0** · autoridad de framing público Artist: membership facts, lifecycle de Events, Transmissions explícitas y decoración de la página canónica.
- `index.php` — 🟡 MOD · **+19 / −1** · conecta el enhancer Artist, carga la capa Roster y neutraliza metadata estática que simulaba géneros.
- `js/public-roster.js` — 🟢 NEW · **+143 / −0** · agrupa Roster con datos reales, orden estable, enlaces canónicos y reutilización de la Promise pública existente.
- `js/public-runtime-loader.js` — 🟡 MOD · **+1 / −1** · incorpora el módulo Roster al runtime público canónico después de `app.js`.

### VISUAL

- `css/public-roster.css` — 🟢 NEW · **+44 / −0** · tratamiento brutalista Roster/Artist, estados active/alumni/network, responsive, touch targets y reduced motion.

### TESTS

- `tests/public-roster-contract.php` — 🟢 NEW · **+74 / −0** · clasificación, membership facts, lifecycle Event, privacidad editorial, navegación canónica y ausencia de request/API duplicada.
- `tests/e2e/public-roster-phase-c.spec.mjs` — 🟢 NEW · **+62 / −0** · grupos del Roster, enlaces canónicos, legibilidad mobile, targets táctiles y no overflow.

### SNAPSHOT

- `README.md` — 🟡 MOD · **AUTO** · este mismo snapshot; su conteo se excluye para evitar referencia circular.

## Validación

- Base exacta: `main` `1abbe4bf11b9f3b44fcf49732e8113e8a8bc7ab8`.
- Esa base quedó con `BRVTAL CI / validate` verde en el run **#628**.
- Issue: **#407** · parent: **#398 Phase C**.
- #179 y #180 ya están cerrados y el lifecycle/history de membership está **VALIDATED IN CODE** en la base.
- El nuevo Roster no modifica schema ni `api/public.php`; consume los campos públicos de Artists que ya existen.
- Pendiente: abrir PR, ejecutar BRVTAL CI + CodeRabbit, corregir findings válidos, squash merge y verificar el CI del SHA exacto de `main`.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobar el deploy real.

## Qué sigue

1. Validar #407 mediante PR + BRVTAL CI + CodeRabbit.
2. Squash merge solo con `validate` verde y revisión limpia.
3. Verificar el CI del SHA exacto resultante en `main`.
4. Continuar #398 Phase C con **Sets / listening discovery**, reutilizando relaciones estructuradas reales.
5. Repetir mediciones de performance sobre el deploy exacto después de los cambios públicos; las métricas anteriores quedan como baseline histórico.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Visión pública: `#398`.
- Roster público: `#407`.
- Lifecycle Artist: `#179`, `#180`, PR `#406`.
- Estrategia de validación: `docs/TESTING.md`.
