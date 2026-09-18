# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí el snapshot exacto de lo que cambió y un panorama general actualizado de lo pendiente.

## Qué se hizo

- Continúa #451 con un bloque P2 acotado en `discadmin/settings-v2.js`.
- `save()` deja de concentrar toda la lógica condicional de General, Social, SEO y Analytics.
- Cada sección tipada tiene ahora un handler pequeño y explícito; `save()` solo orquesta feedback + dispatch.
- Se preservan validaciones, merge de JSON existente, claves desconocidas, normalización de GTM y limpieza de claves legacy.
- La cobertura Playwright valida Social, SEO y Analytics además del flujo General ya existente.
- También verifica que URLs/containers inválidos no persistan cambios.
- No cambia API, formato almacenado, navegación, permisos ni UI visible.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente.
- `discadmin/settings-v2.js` — handlers tipados para reducir complejidad en guardado.
- `tests/e2e/discadmin-settings-v2.spec.mjs` — cobertura de persistencia y validación por sección.

## Validación

- Base exacta: `main` `f3ce5cd3f6d60b02f5985e1018e7639e88ca9642`.
- Esa base pasó **BRVTAL CI #997** y queda **VALIDATED IN CODE**.
- El PR debe pasar BRVTAL CI, SonarQube Cloud y CodeRabbit sobre su SHA exacto antes del squash merge.
- Tras el merge se verificará BRVTAL CI sobre el SHA exacto resultante de `main`.
- CI verde significa **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Tachar `settings-v2.js` en #451 solo después del merge y del CI verde del `main` exacto.
- Continuar con el siguiente P2 que siga reproduciéndose.
- Resolver en un bloque separado el warning de Sonar sobre `sonar.python.version`, usando la versión/rango real del proyecto.
- Revalidar aparte el bloque Sonar de `Math.random()` reportado por el usuario antes de modificarlo.
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
