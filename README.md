# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- El lifecycle de Events mantiene ahora sus timestamps en servidor en vez de depender de que DISCADMIN o un cliente los envíe manualmente.
- La primera entrada a un estado público activo establece `published_at` si todavía falta.
- Una transición a `cancelled` establece `cancelled_at`; una transición a `finished` establece `finished_at`.
- Si un Event activo legacy carece de `published_at` y pasa a un estado histórico, se registra `published_at` en esa transición para conservar prueba de que ya era público.
- Un Event que pasa directamente de `draft` a `archived` no recibe `published_at`, por lo que no se inventa historial de publicación.
- Bulk Actions reutiliza la misma política bajo el lock/transacción existente; no existe una segunda implementación divergente.
- No hay cambios de esquema, migraciones ni mutaciones de datos de producción.

## Archivos modificados en este deploy

- `config/event_lifecycle.php` — política canónica server-side para derivar timestamps de lifecycle de Events.
- `api/index.php` — aplica la política al crear/editar Events y deja los timestamps fuera de los campos editables directos.
- `api/bulk-actions-lib.php` — aplica la misma política a cambios masivos de status dentro de la transacción existente.
- `tests/api-contract.php` — cubre transiciones active/historical, datos legacy y timestamps server-owned.
- `tests/bulk-actions-contract.php` — fija que Bulk Actions reutilice la política canónica.
- `tests/integration/bulk-actions.php` — verifica en MariaDB `published_at` al publicar, preservación al archivar y ausencia de publicación inventada en draft→archive.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `97c31cfe940b23ff785e980f5fae39e87cc852a1`.
- Ese SHA exacto tenía BRVTAL CI completo, PHP 8.5 Compatibility y Backup Recovery Rehearsal en success.
- No hay cambios de esquema ni migraciones; los timestamps ya existen por Content Core.
- La política se aplica a mutaciones futuras; no se ejecuta un backfill masivo sobre producción.
- Pendiente de **BRVTAL CI / validate**, **PHP 8.5 Compatibility / php85**, **README Deploy Snapshot · PR** y **Backup Recovery Rehearsal** del PR.
- Tras el merge se verificará la matriz completa sobre el SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir en esta misma rama cualquier fallo detectado.
2. Con CI verde, hacer squash merge.
3. Verificar BRVTAL CI completo, PHP 8.5 y Backup Recovery sobre el SHA exacto resultante de `main`.
4. Confirmar #192 cerrado y continuar con el siguiente issue público prioritario vigente.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
