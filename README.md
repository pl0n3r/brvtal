# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- El parser del API administrativo ahora valida explícitamente la forma de URL permitida por cada recurso conocido.
- Resources de colección única como `settings`, `auth`, `dashboard`, `upload`, `health` y `public` rechazan segmentos adicionales.
- Events, Artists, Sets, Media, Pages y Ticket Types aceptan únicamente colección o `/{id}` numérico; Events conserva la acción canónica `/{id}/lineup`.
- Rutas ambiguas como `/events/foo`, `/events/42/foo`, `/settings/1` o `/auth/extra` fallan cerradas y ya no pueden caer silenciosamente al CRUD genérico.
- El contrato API cubre formas válidas e inválidas, incluido el caso `settings` documentado en #250.
- No hay cambios de esquema, migraciones, permisos ni datos de producción.

## Archivos modificados en este deploy

- `api/route.php` — contrato fail-closed para formas de ruta conocidas y acciones soportadas.
- `tests/api-contract.php` — regresiones de routing para colecciones, items, lineup y segmentos inválidos.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `854b08b124dbf7519c120bb4f67b7398999abc9a`.
- Ese SHA exacto tenía BRVTAL CI completo —fast, database, Chromium, WebKit, real-stack y validate—, PHP 8.5 Compatibility y Backup Recovery Rehearsal en success.
- Pendiente de gates del PR y, tras el merge, matriz completa sobre el SHA exacto nuevo de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir cualquier fallo en esta misma rama.
2. Con CI verde, hacer squash merge y verificar la matriz completa sobre el nuevo SHA exacto de `main`.
3. Continuar con #160/#189 como un batch separado de semántica transaccional para PUT/DELETE sobre IDs inexistentes.
4. Mantener #332 separado como incidencia operativa de Production Performance / HTTP 403.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
