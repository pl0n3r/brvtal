# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Content Core impide que un `PUT` vacíe la identidad editorial requerida de Events (`title`), Artists (`name`) o Sets (`title`), preservando updates parciales que no tocan esos campos.
- La validación temporal rechaza arrays/objetos antes de convertirlos a string, evitando warnings PHP y devolviendo un `422 INVALID_DATE` limpio.
- La regresión real-stack de Content Core usa identidades únicas, limpia Page/Ticket/Set/Artist/Event en `finally` y cubre directamente el PUT parcial de Ticket Type contra el estado persistido.
- Bulk Actions al publicar Blog o Releases ahora aplica el mismo lifecycle de `published_at` que sus APIs canónicos: crea el timestamp una sola vez y lo conserva en cambios posteriores de estado.
- Se ampliaron contratos PHP, real-stack y MariaDB para proteger estos invariantes.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo exacto de este deploy.
- `api/content-validation.php` — centraliza identidad requerida, tipos temporales seguros y documentación del contrato.
- `api/bulk-actions-lib.php` — preserva el lifecycle `published_at` de Blog y Releases en cambios masivos.
- `tests/content-validation-contract.php` — protege identidad requerida y fechas no escalares.
- `tests/e2e/content-validation-real-stack.spec.mjs` — valida PUTs reales, tipos inválidos y cleanup determinístico.
- `tests/bulk-actions-contract.php` — protege el lifecycle de publicación masiva.
- `tests/integration/bulk-actions.php` — verifica en MariaDB el stamp/preservación de `published_at` para Blog y Releases.

## Validación

- Base exacta: `main` `cea4a357e518fac4bf39abed1acb9742b726280e`, con `BRVTAL CI / validate` verde (run #545).
- No había PRs abiertos al crear `fix/content-integrity-review-quick-wins`.
- Issues cubiertos: `#157`, `#168`.
- También incorpora los tres hardenings accionables que CodeRabbit detectó después del merge de `#379`: temporal no escalar, PUT parcial real de Ticket Type y cleanup único/autolimpiante del real-stack.
- No hay migración de base de datos, cambios de schema, restore, delete masivo ni mutación de datos de producción.
- Las pruebas real-stack solo crean datos CI con namespace único y los eliminan en `finally`; la integración MariaDB usa tablas temporales en la base de test.
- Pendiente en este snapshot: `BRVTAL CI / validate`, revisión advisory de CodeRabbit y análisis automático de SonarQube Cloud sobre el head final.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobar el deploy real y la superficie correspondiente.

## Qué sigue

1. Resolver en esta misma rama cualquier finding válido de BRVTAL CI, CodeRabbit o SonarQube Cloud.
2. Hacer squash merge solo con `BRVTAL CI / validate` verde y después de revisar los threads finales de CodeRabbit.
3. Verificar `BRVTAL CI / validate` del SHA exacto resultante en `main`.
4. Continuar con otro lote de varios Quick Wins compatibles; `#173` queda para un batch específico de invariantes de Event porque requiere API + Bulk Actions.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de validación: `docs/TESTING.md`.
- Issues abordados: `#157`, `#168`.
