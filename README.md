# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Se implementó **#398 / Phase B — EVENT RECORD** mediante el issue **#404**.
- La URL canónica `/events/{slug}` conserva una sola entidad y cambia de presentación según el lifecycle: experiencia activa antes/durante el Event y registro histórico permanente después.
- Archive y Event Record comparten `brvtal_public_event_is_historical(...)`; no existe una segunda clasificación de lifecycle.
- Las decisiones de visibilidad, archivo, ticketing y ventanas de Ticket Types usan un único reloj memoizado por request, evitando estados incoherentes si una petición cruza medianoche.
- Un Event histórico conserva artwork, fecha, ubicación, status y lineup, pero elimina la acción comercial mediante la política canónica existente.
- Lineup, Sets y **TRANSMISSIONS** usan únicamente relaciones estructuradas y registros publicados: `event_artists`, `sets_media.event_id` y `blog_post_relations`.
- **Memories no se infieren** por fecha, título o filename: todavía no existe una relación Event ↔ Media estructurada.
- La capa visual mantiene BRVTAL negro/rojo, grano, scanlines e interferencia con verde ácido usado solo como señal; el modo histórico es más archival sin convertirse en una UI corporativa.
- No se añadió JavaScript público, endpoint paralelo, tabla nueva ni migración de schema.
- La cobertura de Event Record ahora ejecuta `brvtal_public_page_data()` contra MariaDB con Events activos, históricos y draft, incluyendo relaciones publicadas/no publicadas y supresión real de tickets.

## Archivos modificados en este deploy

**Diff funcional:** `7 archivos` · **+451** líneas · **−22** líneas *(sin contar README, porque este snapshot modifica su propio diff al actualizarse).*  
Leyenda: 🟢 nuevo · 🟡 modificado · `+ / −` líneas frente al `main` base de este deploy.

### LIFECYCLE / PUBLIC DELIVERY

- `api/public-archive.php` — 🟡 MOD · **+1 / −7** · usa la clasificación histórica canónica compartida.
- `config/public_page.php` — 🟡 MOD · **+59 / −11** · transforma la página Event existente en Event Record y conecta Lineup, Sets y Transmissions estructuradas.
- `config/public_visibility.php` — 🟡 MOD · **+55 / −3** · centraliza estado histórico y reloj coherente por request para lifecycle/ticketing.

### VISUAL

- `css/public-event-record.css` — 🟢 NEW · **+1 / −0** · capa brutalista Event Record con estados active/historical, responsive y reduced motion.

### TESTS / TOOLING

- `package.json` — 🟡 MOD · **+1 / −1** · incluye Event Record en la suite MariaDB canónica.
- `tests/e2e/public-event-record.spec.mjs` — 🟢 NEW · **+60 / −0** · desktop/mobile, CTA activo, framing histórico, touch targets y no overflow.
- `tests/event-record-contract.php` — 🟢 NEW · **+274 / −0** · lifecycle, reloj único, renderer y cobertura MariaDB de `page_data()` con filtros de publicación y ticketing.

### SNAPSHOT

- `README.md` — 🟡 MOD · **AUTO** · este mismo snapshot; su conteo se excluye para evitar referencia circular.

## Validación

- Base exacta: `main` `060013230c0d9d3058d4e27bd2759aeb7cd1b485`.
- La base quedó con `BRVTAL CI / validate` verde en el run **#607**.
- PR: `#405` · issue: `#404` · parent: `#398` Phase B.
- Run #608 detectó un bootstrap incompleto del contrato aislado; se cargó `public_seo.php` sin cambiar lógica de producto.
- Run #610 dejó fast, Chromium, real-stack, database y `validate` verdes.
- CodeRabbit detectó dos riesgos válidos después de #610: reloj implícito duplicado cerca de medianoche y falta de cobertura ejecutable sobre `brvtal_public_page_data()`; ambos quedaron corregidos sin ampliar arquitectura.
- El contrato MariaDB usa únicamente tablas temporales y exige `BRVTAL_INTEGRATION_TESTS=1` + nombre `brvtal_test*`.
- Las relaciones públicas siguen publication-gated; drafts no obtienen acciones comerciales.
- `api/public.php` continúa siendo la API pública canónica y #212 i18n sigue **NOT IMPLEMENTED**.
- Pendiente: CI y revisión automática verdes sobre el head posterior a estas correcciones antes del squash merge.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobar el deploy real.

## Qué sigue

1. Validar el head corregido de #405 con BRVTAL CI y CodeRabbit.
2. Hacer squash merge solo con `validate` verde y sin findings válidos pendientes.
3. Verificar el `BRVTAL CI / validate` del SHA exacto resultante en `main`.
4. Continuar #398 con la siguiente fase sin inventar la relación Event ↔ Media.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de validación: `docs/TESTING.md`.
- Arquitectura de configuración: `docs/CONFIGURATION.md`.
- Visión pública: `#398`.
- Issue abordado: `#404`.
