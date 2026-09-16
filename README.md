# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- El workflow canónico de Events deja de guardar Event, Ticket Types y lineup mediante una cadena de mutaciones independientes: DISCADMIN envía una sola operación protegida a `/api/event-workflow.php`.
- Event, Ticket Types, roster/lineup y SEO del Event se persisten dentro de una única transacción MariaDB; un fallo tardío revierte también las escrituras anteriores de la misma operación.
- Los Ticket Types existentes se actualizan como patch sobre la fila bloqueada para preservar metadata soportada que hoy no aparece en el editor visual, como currency y ventanas de disponibilidad.
- El workflow conserva Admin Activity dentro de la misma transacción y valida ownership de Ticket Types, artistas seleccionados y estado editorial antes del commit.
- Un Event no-draft guardado por este workflow requiere nombre, fecha/hora y ciudad; los drafts incompletos siguen permitidos.
- El bridge de DISCADMIN mantiene el bloqueo cuando Tickets o lineup todavía no terminaron de cargar, evitando sobrescribir estado desconocido con listas vacías.
- SEO de Events entra al mismo request atómico en vez de depender de una segunda mutación posterior.
- Se reemplazó el smoke real-stack de Content Core para demostrar un único request atómico, persistencia completa y rollback ante un fallo tardío del roster.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo exacto de este deploy.
- `api/event-workflow-lib.php` — normalización y transacción canónica para Event + Ticket Types + lineup.
- `api/event-workflow.php` — endpoint autenticado/CSRF para el workflow atómico y Admin Activity.
- `discadmin/event-workflow.js` — puente del Event editor hacia el único guardado transaccional y protección de estado aún no cargado.
- `discadmin/event-workflow-seo.js` — incorpora metadata SEO al mismo body antes de enviar la transacción.
- `discadmin/index.php` — carga los bridges versionados del workflow de Events dentro del shell existente.
- `package.json` — incorpora la regresión MariaDB del workflow al suite de integración canónico.
- `tests/e2e/content-core-real-stack.spec.mjs` — valida el guardado atómico real PHP/MariaDB/Chromium y conserva la regresión de Media integrity.
- `tests/event-workflow-contract.php` — contrato rápido para normalización, invariantes y wiring del workflow.
- `tests/integration/event-workflow.php` — demuestra commit completo, patch de Ticket metadata y rollback ante un fallo tardío.

## Validación

- Base exacta: `main` `792b05fd3e51439abffba2dfffb4e1938fb8ddc4`, con `BRVTAL CI / validate` verde (run #564).
- Issue cubierto: `#178`.
- No hay migración, cambio de schema, restore, bulk delete ni mutación de datos de producción.
- El cambio activa los gates aplicables de MariaDB, Chromium y real-stack por tocar API, DISCADMIN y pruebas de integración/E2E; `discadmin/index.php` también hace que el clasificador ejecute el WebKit auth gate existente.
- La regresión MariaDB opera únicamente sobre tablas temporales dentro de la base `brvtal_test...` y fuerza un error tardío para demostrar que Event y Ticket no quedan persistidos parcialmente.
- El smoke real-stack crea datos CI con identidad propia y limpia el Event creado al finalizar.
- Pendiente en este snapshot: `BRVTAL CI / validate`, revisión CodeRabbit y análisis automático de SonarQube Cloud sobre el head final.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobar el deploy real y una operación autenticada controlada.

## Qué sigue

1. Resolver en esta misma rama cualquier finding válido de BRVTAL CI, CodeRabbit o SonarQube Cloud.
2. Hacer squash merge solo con `BRVTAL CI / validate` verde y revisar los threads finales de CodeRabbit.
3. Verificar `BRVTAL CI / validate` del SHA exacto resultante en `main`.
4. Continuar con `#173`: aplicar la misma invariancia de Event no-draft a los caminos genéricos CRUD/Bulk que quedan fuera del workflow canónico.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de validación: `docs/TESTING.md`.
- Issue abordado: `#178`.
