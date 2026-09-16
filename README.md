# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Se centralizó el invariante editorial de Events: un draft puede estar incompleto, pero cualquier estado distinto de `draft` requiere `title`, `event_date` y `city`.
- El API genérico de Events aplica ese contrato tanto al crear como al actualizar, evaluando siempre el estado final de la fila antes de escribir.
- Un Event ya publicado no puede perder fecha o ciudad mediante un PUT parcial: la mutación responde 422 y la fila conserva el valor anterior.
- Bulk Actions valida todos los Events seleccionados antes de cambiar estados; si uno está incompleto, la operación completa falla antes de mutar ninguna fila.
- Las transiciones válidas siguen conservando el comportamiento existente de lifecycle timestamps (`published_at`, `cancelled_at`, `finished_at`).
- Se añadieron contrato PHP, regresión MariaDB y smoke autenticado PHP/MariaDB/Chromium para demostrar rechazo sin mutación y publicación válida cuando el Event queda completo.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo exacto de este deploy.
- `api/bulk-actions-lib.php` — valida el estado editorial completo de cada Event antes de una transición masiva.
- `api/content-validation.php` — helper canónico `brvtal_event_publication_error()` para el contrato no-draft.
- `api/index.php` — aplica el invariante al POST y PUT genéricos de Events usando el estado final de la fila.
- `tests/e2e/event-publication-invariant-real-stack.spec.mjs` — comprueba 422/no-mutación y caminos válidos por CRUD y Bulk Actions contra PHP + MariaDB reales.
- `tests/e2e/run-content-core-real-stack.sh` — incorpora el nuevo smoke de invariantes al gate real-stack canónico.
- `tests/event-publication-invariant-contract.php` — contrato rápido del helper y del wiring server-side.
- `tests/integration/bulk-actions.php` — valida en MariaDB rechazo atómico de Events incompletos y conserva cobertura de lifecycle Bulk.

## Validación

- Base exacta: `main` `98aa0f1a8f3bc0a17c50e62dbc497705f08a6bdb`, con `BRVTAL CI / validate` verde (run #566).
- Issue cubierto: `#173`.
- No hay migración, cambio de schema, restore, bulk delete ni mutación de datos de producción.
- La regresión MariaDB usa únicamente tablas temporales dentro de una base `brvtal_test...`.
- El smoke real-stack crea Events CI con identidad propia, confirma el estado persistido después de cada rechazo y elimina sus fixtures al finalizar.
- Pendiente en este snapshot: `BRVTAL CI / validate`, revisión CodeRabbit y análisis automático de SonarQube Cloud sobre el head final.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobar el deploy real y una operación autenticada controlada.

## Qué sigue

1. Resolver en esta misma rama cualquier finding válido de BRVTAL CI, CodeRabbit o SonarQube Cloud.
2. Hacer squash merge solo con `BRVTAL CI / validate` verde y revisar los threads finales de CodeRabbit.
3. Verificar `BRVTAL CI / validate` del SHA exacto resultante en `main`.
4. Recalcular el backlog abierto contra el nuevo `main` y continuar con el siguiente defecto de integridad/consistencia de mayor impacto sin duplicar trabajo ya resuelto.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de validación: `docs/TESTING.md`.
- Issue abordado: `#173`.
