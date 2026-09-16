# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Se inició **#398 / Phase B — EVENT RECORD** mediante el issue enfocado **#404**.
- La URL canónica `/events/{slug}` evoluciona según el lifecycle existente: un Event activo conserva framing de experiencia/tickets y el mismo Event pasa a framing de archivo cuando su estado o fecha lo vuelve histórico.
- Archive y Event Record comparten una única clasificación `brvtal_public_event_is_historical(...)`; no hay lógica de lifecycle duplicada entre superficies.
- Los Event Records históricos mantienen artwork, fecha, ubicación, status y lineup real; los tickets siguen desapareciendo mediante la política comercial canónica ya existente.
- Los Sets se obtienen únicamente mediante la relación existente `sets_media.event_id` y solo si están publicados.
- Las piezas editoriales se muestran como **TRANSMISSIONS** únicamente cuando `blog_post_relations` enlaza explícitamente un post publicado con el Event.
- **Memories no se infieren** por fecha, nombre de archivo o título: `media` todavía no posee una relación estructurada con Events, así que esta fase no presenta una sección falsa/vacía.
- El Event Record mantiene la estética BRVTAL: negro/charcoal, rojo, grano/ruido, scanlines, interferencia y señal verde ácido controlada. El modo histórico es más denso/archival sin volverse limpio o corporativo.
- No se añadió JavaScript público ni otra request de API para esta experiencia; la diferenciación active/historical se resuelve server-side y con CSS específico.
- No hay migración ni cambio de schema.

## Archivos modificados en este deploy

**Diff funcional:** `6 archivos` · **+239** líneas · **−18** líneas *(sin contar README, porque este snapshot modifica su propio diff al actualizarse).*  
Leyenda: 🟢 nuevo · 🟡 modificado · `+ / −` líneas frente al `main` base de este deploy.

### LIFECYCLE / PUBLIC DELIVERY

- `config/public_visibility.php` — 🟡 MOD · **+20 / −0** · añade la clasificación histórica canónica compartida por Archive y Event Record.
- `api/public-archive.php` — 🟡 MOD · **+1 / −7** · elimina lógica duplicada y usa la clasificación histórica central.
- `config/public_page.php` — 🟡 MOD · **+59 / −11** · convierte la página Event existente en Event Record lifecycle-aware y conecta Lineup, Sets y Transmissions estructuradas.

### VISUAL

- `css/public-event-record.css` — 🟢 NEW · **+1 / −0** · capa brutalista específica de Event Record: grano, scanline, interferencia, señal ácida, estados active/historical y responsive móvil.

### TESTS

- `tests/event-record-contract.php` — 🟢 NEW · **+98 / −0** · contratos de lifecycle, relaciones estructuradas, ausencia de Memories inferidas y render active/historical.
- `tests/e2e/public-event-record.spec.mjs` — 🟢 NEW · **+60 / −0** · cobertura visual desktop/mobile, CTA activo, framing histórico, touch targets y ausencia de overflow.

### SNAPSHOT

- `README.md` — 🟡 MOD · **AUTO** · este mismo snapshot; su conteo exacto se excluye para evitar una referencia circular al actualizar el diff.

## Validación

- Base exacta: `main` `060013230c0d9d3058d4e27bd2759aeb7cd1b485`.
- La base quedó con `BRVTAL CI / validate` verde en el run **#607**.
- Issue cubierto: `#404` · parent `#398` Phase B.
- No hay migración, nueva tabla, mini-admin, endpoint paralelo ni JavaScript público adicional.
- `api/public.php` sigue siendo la API pública canónica; esta fase trabaja sobre el renderer server-side existente.
- Las relaciones usadas son únicamente `event_artists`, `sets_media.event_id` y `blog_post_relations` con publication gates existentes.
- Memories quedan fuera hasta que el backend posea una relación Event ↔ Media explícita; no se introduce heurística ni dato duplicado.
- La especificación i18n de #212 sigue **NOT IMPLEMENTED**.
- El primer run de PR (#608) detectó que el contrato aislado del renderer no cargaba `public_seo.php`; se corrigió el bootstrap del test sin cambiar la implementación.
- Pendiente: `BRVTAL CI / validate` verde sobre el head de esta rama, revisión automática y correcciones válidas antes del merge.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobar el deploy real.

## Qué sigue

1. Ejecutar BRVTAL CI completo sobre el PR de #404 y resolver findings válidos sin relajar pruebas.
2. Hacer squash merge solo con `validate` verde.
3. Verificar `BRVTAL CI / validate` del SHA exacto resultante en `main`.
4. Continuar #398 con la siguiente fase después de cerrar Event Record; la relación estructurada de Memories se resolverá con el trabajo de Cultural Archive, no con inferencias.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de validación: `docs/TESTING.md`.
- Arquitectura de configuración: `docs/CONFIGURATION.md`.
- Visión pública: `#398`.
- Issue abordado: `#404`.
