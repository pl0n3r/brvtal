# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- DISCADMIN mantiene el botón MENU accesible durante scroll móvil mediante el header sticky del workspace.
- Content Core conserva Date y Status en el listado móvil canónico de Events.
- Las acciones `.icon` de Content Core mantienen un target táctil mínimo de 44×44 px en móvil.
- Releases conserva visible la atribución de artistas vinculados en tablet y móvil.
- Theme Studio mantiene tabs de al menos 44 px de alto en móvil.
- Playwright cubre scroll del MENU, metadata editorial visible y tamaños táctiles reales.
- No hay cambios de API, esquema, migraciones, permisos ni datos de producción.

## Archivos modificados en este deploy

- `discadmin/admin-shell.css` — header móvil sticky y mínimos táctiles de Content Core / Theme Studio.
- `discadmin/content-core.css` — Date/Status permanecen visibles en Events móvil.
- `discadmin/releases.css` — atribución de artistas permanece visible en responsive.
- `tests/e2e/discadmin-mobile-quick-wins.spec.mjs` — regresiones browser para #222, #223, #241, #245 y #283.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `5eaf09919e129e8d044da0eadd95ad9644fb4793`.
- Ese SHA exacto tenía BRVTAL CI completo, PHP 8.5 Compatibility y Backup Recovery Rehearsal en success.
- Pendiente de gates del PR y, tras el merge, matriz completa sobre el SHA exacto nuevo de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir cualquier fallo en esta misma rama.
2. Con CI verde, hacer squash merge para dejar un único commit final del batch en `main`.
3. Verificar BRVTAL CI completo, PHP 8.5 y Backup Recovery sobre el SHA exacto resultante.
4. Continuar agrupando Quick Wins relacionados en batches antes de abordar cambios estructurales.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
