# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí el snapshot exacto de lo que cambió y un panorama general actualizado de lo pendiente.

## Qué se hizo

- Continúa #451 con un bloque P3 mecánico reportado por Sonar.
- Los helpers de escape HTML de `js/app.js` y `js/public-roster.js` dejan de usar cinco regex globales con `.replace(.../g,...)` y pasan a `String.prototype.replaceAll()`.
- Se conservan exactamente las entidades usadas para `&`, `<`, `>`, comillas dobles y comillas simples.
- No se cambian otros `.replace()` de una sola ocurrencia: normalización de rutas, fechas y otras transformaciones permanecen intactas.
- La cobertura Playwright verifica que Home y Roster rendericen caracteres especiales literalmente, escapen atributos sensibles (`src`/`data-image`) y no creen elementos HTML a partir de contenido CMS.
- No cambia API, payload, rutas, orden editorial, estilos ni persistencia.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente.
- `js/app.js` — `replaceAll()` en el helper de escape HTML del frontend dinámico.
- `js/public-roster.js` — `replaceAll()` en el helper de escape HTML del roster.
- `tests/e2e/public-runtime-fallback.spec.mjs` — regresión de escaping del Home dinámico.
- `tests/e2e/public-roster-phase-c.spec.mjs` — regresión de escaping del roster público.

## Validación

- Base exacta: `main` `599e07893e2172ead205eb4ec36115609150982c`.
- Esa base pasó **BRVTAL CI #1003** y queda **VALIDATED IN CODE**.
- El PR debe pasar BRVTAL CI, SonarQube Cloud y CodeRabbit sobre su SHA exacto antes del squash merge.
- Tras el merge se verificará BRVTAL CI sobre el SHA exacto resultante de `main`.
- CI verde significa **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Tachar el bloque `replaceAll()` en #451 solo después del merge y del CI verde del `main` exacto.
- Revalidar el conjunto completo de warnings de `Math.random()` antes de modificar comportamiento.
- Resolver aparte el warning de configuración `sonar.python.version` usando la versión/rango real del proyecto.
- Continuar los P2 de #451 que todavía reproduzcan en código actual.
- Reconciliar PR #434 / Memories aparte, sin ejecutar migraciones de producción automáticamente.
- Mantener #479, #480 y #481 como frentes separados.

## Panorama general pendiente

- **Sonar / calidad:** #451 continúa por riesgo y área; los bloques completados se tachan únicamente tras merge + exact-main CI.
- **Apariencia:** #149 — completar Light en módulos modernos.
- **Hero Slider:** #221 — integridad editorial de media; #480 — regresión visual del Hero en desktop. #237 ya está cerrado.
- **Seguridad editorial / navegación:** #257, #216, #174 y #193.
- **SEO editorial / entrega pública:** #182, #214, #204 y #272 antes de #390; #479 corrige imágenes sin `alt`; #481 integra IndexNow.
- **Content / edición:** #224 y #252 — Ticket Types e integridad de media.
- **Activity / operaciones:** #232 y #195 — historial navegable y cobertura de audit log.
- **Bulk Actions:** #275 — catálogos mayores de 500 sin truncado silencioso.
- **Dashboard / DISCADMIN:** #348 y #351 — IA y Theme Studio.
- **Archivo cultural:** #398 / #403 — Event Records y relaciones estructuradas.
- **Memories:** #415 / PR #434 requiere reconciliación con `main`; ninguna migración de producción automática.
- **Idioma:** #212 — español canónico + inglés automático por fases.
- **Backups:** #389 — scheduling seguro y Drive opcional con autorización externa.
