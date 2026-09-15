# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- El shell de DISCADMIN impone en móvil un hit area mínimo de 44 px para las acciones de Content Health, Global Search, Bulk Actions y Admin Activity.
- Global Search mantiene 44 px en trigger, close y filas de resultados.
- Bulk Actions mantiene 44 px en trigger, close, select all, apply y filas; las checkboxes reciben un área táctil mayor sin cambiar la lógica de selección.
- Admin Activity mantiene 44 px en filtro y botones de acción, incluidos DETAIL, HISTORY, OPEN, REFRESH y CLOSE.
- Content Health mantiene 44 px en la acción OPEN.
- La regresión Playwright carga los estilos reales de cada módulo después del shell y verifica que el contrato móvil prevalezca.
- No hay cambios de API, esquema, migraciones, permisos ni datos de producción.

## Archivos modificados en este deploy

- `discadmin/admin-shell.css` — contrato táctil móvil centralizado para los cuatro componentes.
- `tests/e2e/discadmin-mobile-quick-wins.spec.mjs` — regresiones browser de #274, #286, #288 y #290.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `af5ce2de91a53182c2d1546abe76ad5f7731b52c`.
- Ese SHA exacto tenía BRVTAL CI completo, PHP 8.5 Compatibility y Backup Recovery Rehearsal en success.
- Pendiente de gates del PR y, tras el merge, matriz completa sobre el SHA exacto nuevo de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir cualquier fallo en esta misma rama.
2. Con CI verde, hacer squash merge.
3. Verificar BRVTAL CI completo, PHP 8.5 y Backup Recovery sobre el SHA exacto resultante de `main`.
4. Continuar con la cola de quick wins del inventario, priorizada por menor esfuerzo y riesgo.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
