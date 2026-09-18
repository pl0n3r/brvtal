# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí el snapshot exacto de lo que cambió y un panorama general actualizado de lo pendiente.

## Qué se hizo

- Reduce duplicación y complejidad del manejo de teclado de los formularios modales de DISCADMIN dentro de #451.
- Legacy y Content Core comparten ahora el mismo focus trap para Tab / Shift+Tab.
- Escape usa una única ruta de selección/cierre del diálogo activo, preservando los handlers nativos de cada editor.
- Se mantiene la prioridad del modal legacy si por error hubiera más de un diálogo abierto.
- Se añade regresión Playwright para comprobar el focus trap de Content Core además del modal legacy existente.
- No cambia validación, guardado, lifecycle ni contenido de formularios.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente.
- `discadmin/admin-form-dialogs.js` — helpers compartidos para focus trap y cierre por teclado.
- `tests/e2e/discadmin-form-dialogs.spec.mjs` — cobertura de Tab / Shift+Tab en Content Core.

## Validación

- Base exacta: `main` `d2b045a36aab51d0929fb192c35a5a9966c6ce57`.
- Esa base pasó BRVTAL CI #970 y queda **VALIDATED IN CODE**.
- El PR debe pasar BRVTAL CI, SonarCloud y CodeRabbit sobre su SHA exacto antes del squash merge.
- Tras el merge se verificará BRVTAL CI sobre el SHA exacto resultante de `main`.
- CI verde significa **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Cerrar este grupo pequeño de #451 y continuar el burn-down con deuda que siga reproduciéndose en el código actual.
- Reconciliar PR #434 / Memories aparte, sin mezclarlo con refactors de calidad.
- Mantener #479, #480 y #481 como frentes separados de SEO/experiencia pública.

## Panorama general pendiente

- **Sonar / calidad:** #451 continúa por riesgo y área; varios P1 del export original ya no se reproducen en `main`.
- **Apariencia:** #149 — completar Light en módulos modernos.
- **Hero Slider:** #221 — integridad editorial de media; #480 — regresión visual del Hero en desktop.
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
