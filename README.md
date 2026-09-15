# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- `⌘K / Ctrl+K` queda reservado a Global Search y ya no alcanza el listener legacy de Theme Studio.
- El sidebar móvil, Global Search, Bulk Actions y Admin Activity comparten un boundary de accesibilidad para focus trap, Escape y restauración de foco.
- Bulk Actions recibe foco dentro del diálogo desde que abre, incluso durante la carga de registros.
- Admin Activity DETAIL/HISTORY recibe foco inicial dentro del modal y permite cierre por Escape.
- Playwright cubre ciclos Tab / Shift+Tab, Escape, restauración y la colisión real del shortcut.
- No hay cambios de API, esquema, migraciones, permisos ni datos de producción.

## Archivos modificados en este deploy

- `discadmin/admin-modal-accessibility.js` — boundary común de teclado/foco para overlays DISCADMIN y ownership de `⌘K / Ctrl+K`.
- `discadmin/index.php` — carga el boundary después de los componentes y del shell.
- `tests/e2e/discadmin-keyboard-modal-quick-wins.spec.mjs` — regresiones browser para #156, #165, #169, #171 y #175.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `f4638895866fd5c3ba095fffa5eb8090ad195669`.
- Ese SHA exacto tenía BRVTAL CI completo —incluido WebKit—, PHP 8.5 Compatibility y Backup Recovery Rehearsal en success.
- Pendiente de gates del PR y, tras el merge, matriz completa sobre el SHA exacto nuevo de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir cualquier fallo en esta misma rama.
2. Compactar la rama nuevamente a un único commit si CI exige cambios.
3. Con CI verde, hacer squash merge y verificar la matriz completa sobre el nuevo SHA de `main`.
4. Continuar con batches de Quick Wins relacionados antes de cambios estructurales.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
