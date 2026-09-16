# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Content Core impide que un `PUT` vacíe la identidad editorial requerida de Events (`title`), Artists (`name`) o Sets (`title`), preservando updates parciales que no tocan esos campos.
- Los JSON con `title`/`name` no escalares se rechazan antes del cast y las fechas no escalares devuelven un `422` limpio, evitando valores `Array` y warnings PHP.
- La regresión real-stack usa identidades únicas, limpia Page/Ticket/Set/Artist/Event en `finally`, cubre directamente el PUT parcial de Ticket Type y comprueba que los `422` no modifiquen datos persistidos.
- Bulk Actions al publicar Blog o Releases aplica el lifecycle de `published_at`: crea el timestamp una sola vez y lo conserva en cambios posteriores de estado.
- La prueba MariaDB es la evidencia ejecutable del lifecycle de publicación masiva, evitando acoplar contratos a una forma concreta del SQL.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo exacto de este deploy.
- `config/bootstrap.php` — rechaza `title`/`name` no escalares al decodificar JSON antes de cualquier conversión a string.
- `api/content-validation.php` — centraliza identidad requerida, tipos temporales seguros y documentación del contrato.
- `api/bulk-actions-lib.php` — preserva el lifecycle `published_at` de Blog y Releases en cambios masivos.
- `tests/content-validation-contract.php` — protege identidad requerida y fechas no escalares.
- `tests/e2e/content-validation-real-stack.spec.mjs` — valida PUTs reales, no persistencia tras `422`, tipos inválidos y cleanup determinístico.
- `tests/integration/bulk-actions.php` — verifica en MariaDB el stamp/preservación de `published_at` para Blog y Releases.

## Validación

- Base exacta: `main` `cea4a357e518fac4bf39abed1acb9742b726280e`, con `BRVTAL CI / validate` verde (run #545).
- No había PRs abiertos al crear `fix/content-integrity-review-quick-wins`.
- Issues cubiertos: `#157`, `#168`.
- También incorpora los tres hardenings accionables que CodeRabbit detectó después del merge de `#379` y los findings válidos de la primera revisión de `#382`.
- No hay migración de base de datos, cambios de schema, restore, delete masivo ni mutación de datos de producción.
- Las pruebas real-stack solo crean datos CI con namespace único y los eliminan en `finally`; la integración MariaDB usa tablas temporales en la base de test.
- El head anterior de `#382` tuvo `fast`, `database`, `chromium`, `real-stack` y `validate` verdes; el head actualizado debe repetir los gates aplicables antes del merge.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobar el deploy real y la superficie correspondiente.

## Qué sigue

1. Esperar `BRVTAL CI / validate` y la revisión final de CodeRabbit sobre el head actualizado.
2. Resolver cualquier finding válido restante en esta misma rama.
3. Hacer squash merge y verificar `BRVTAL CI / validate` del SHA exacto resultante en `main`.
4. Continuar con el siguiente lote de Quick Wins de lifecycle: `#173` + `#180` si siguen vigentes contra el nuevo `main`.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de validación: `docs/TESTING.md`.
- Issues abordados: `#157`, `#168`.
