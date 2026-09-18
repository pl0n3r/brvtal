# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí el snapshot exacto de lo que cambió y un panorama general actualizado de lo pendiente.

## Qué se hizo

- Corrige #237 para que el Hero Slider reevalúe assets y overrides al cruzar el breakpoint de 700 px.
- El runtime reutiliza un único `MediaQueryList` y escucha su evento `change`.
- Al cruzar desktop ↔ mobile se vuelve a generar solo el track del Hero, preservando el índice de la slide activa.
- `mobileSrc` / desktop media se reaplican correctamente al cambiar de breakpoint.
- Las capas reaplican `mobileSrc`, `mobileX`, `mobileY`, `mobileWidth` y `hiddenMobile`.
- La slide activa ya no depende de que sea la primera: media e imágenes activas usan `position === index`, por lo que un re-render responsive conserva la slide actual.
- Controles, dots, contador y estado de pausa quedan fuera del track reemplazado y conservan su estado.
- No se modifica CSS: los estilos existentes ya usan el mismo corte responsive de 700 px.
- Se añade una regresión Playwright desktop → mobile → desktop sobre la segunda slide.
- #221 queda fuera de alcance: este cambio no altera reglas editoriales ni validación de assets inexistentes.
- #480 queda fuera de alcance: el problema visual de tipografía gigante/superpuesta en producción se investiga por separado.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente.
- `js/hero-slider.js` — sincronización runtime al cruzar el breakpoint sin perder la slide activa.
- `tests/e2e/hero-slider.spec.mjs` — regresión responsive de media, capas, `hiddenMobile` y slide activa.

## Validación

- Base exacta: `main` `db4e7072bd1e4f97cffe5fa4970f63b6bf66caf5`.
- Esa base pasó BRVTAL CI #967 y queda **VALIDATED IN CODE**.
- El PR debe pasar BRVTAL CI, SonarCloud y CodeRabbit sobre su SHA exacto antes del squash merge.
- El relay de Sonar debe publicar las annotations detalladas del head exacto del PR.
- Tras el merge se verificará BRVTAL CI sobre el SHA exacto resultante de `main`.
- CI verde significa **VALIDATED IN CODE**, no validación de producción.
- No se ejecutan migraciones ni operaciones destructivas de producción.

## Qué sigue

- Cerrar gates exactos de #237, corregir findings válidos, squash merge y verificar el nuevo `main`.
- Continuar #451 como burn-down de Sonar con cambios pequeños y revalidados.
- Reconciliar PR #434 / Memories aparte, sin mezclarlo con este cambio.
- Atacar por separado #479, #480 y #481 dentro del frente SEO/experiencia pública.

## Panorama general pendiente

- **Sonar / calidad:** #451 continúa por riesgo y área, con PRs pequeños.
- **Apariencia:** #149 — completar Light en módulos modernos.
- **Hero Slider:** #221 — integridad editorial de media; #480 — regresión visual del Hero en desktop.
- **Seguridad editorial / navegación:** #257, #216, #174 y #193.
- **SEO editorial / entrega pública:** #182, #214, #204 y #272 antes de #390; #479 corrige imágenes públicas sin `alt`; #481 integra IndexNow.
- **Content / edición:** #224 y #252 — Ticket Types e integridad de media.
- **Activity / operaciones:** #232 y #195 — historial navegable y cobertura de audit log.
- **Bulk Actions:** #275 — resolver catálogos mayores de 500 sin truncado silencioso.
- **Dashboard / DISCADMIN:** #348 y #351 — IA y Theme Studio.
- **Archivo cultural:** #398 / #403 — Event Records y relaciones estructuradas.
- **Memories:** #415 / PR #434 requiere reconciliación con `main`; ninguna migración de producción automática.
- **Idioma:** #212 — español canónico + inglés automático por fases.
- **Backups:** #389 — scheduling seguro y Drive opcional con autorización externa.
