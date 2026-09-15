# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- PUT y DELETE item-level del CRUD administrativo genérico ahora verifican y bloquean la fila objetivo dentro de una transacción antes de mutarla.
- Un ID inexistente responde `404 / NOT_FOUND` en vez de reportar éxito con `changed: 0` o `deleted: 0`.
- Un PUT idempotente sobre una fila que sí existe conserva semántica de éxito y puede seguir devolviendo `changed: 0`.
- La corrección aplica también a Media, que antes quedaba fuera del guard basado en Admin Activity.
- DELETE exitoso significa que existía una fila objetivo y se eliminó exactamente una fila.
- Se conserva Admin Activity para los recursos auditados y no se amplía este cambio a la política reference-aware de Media Library (#159).
- No hay cambios de esquema, migraciones, permisos ni datos de producción.

## Archivos modificados en este deploy

- `api/index.php` — existencia transaccional fail-closed para PUT/DELETE de recursos item-level.
- `tests/api-contract.php` — contrato de lock, 404 previo a escritura/borrado y preservación de PUT idempotente.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `e6beea430d657e654e15c080be91ccbb2f8fcc32`.
- Ese SHA exacto tenía BRVTAL CI completo —fast, database, Chromium, WebKit, real-stack y validate—, PHP 8.5 Compatibility y Backup Recovery Rehearsal en success.
- Pendiente de gates del PR y, tras el merge, matriz completa sobre el SHA exacto nuevo de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir cualquier fallo en esta misma rama.
2. Con CI verde, hacer squash merge y verificar la matriz completa sobre el nuevo SHA exacto de `main`.
3. Continuar con otro batch pequeño de integridad del API/DISCADMIN sin mezclar la política canónica de Media Library.
4. Mantener #332 separado como incidencia operativa de Production Performance / HTTP 403.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
