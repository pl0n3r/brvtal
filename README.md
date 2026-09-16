# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Las respuestas autenticadas de `discadmin/logs.php` ahora declaran `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` y `Pragma: no-cache`.
- La política se aplica antes de seleccionar la acción, por lo que cubre la vista HTML, la descarga del log, errores y redirects del mismo endpoint.
- Se mantiene `nosniff` y se añade `X-Robots-Tag: noindex, nofollow, noarchive` a nivel HTTP, además del meta robots existente.
- Se añade un contrato de regresión para impedir que el boundary de no-cache desaparezca o quede aplicado solo a una rama del endpoint.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo exacto de este deploy.
- `discadmin/logs.php` — desactiva almacenamiento/cache e indexación para todas las respuestas autenticadas del debug log.
- `tests/admin-logs-cache-contract.php` — protege autenticación, headers y posición común de la política antes de las acciones.

## Validación

- Base exacta: `main` `c1dd30bb40f6e141b387b1a020b2d16954f257ff`, con `BRVTAL CI / validate` verde.
- No había PRs abiertos al crear `security/admin-log-no-store`.
- Issue cubierto: `#372`.
- No hay migración de base de datos ni mutación de datos de producción.
- El endpoint sigue exigiendo sesión administrativa; la limpieza del log conserva CSRF y POST.
- `tests/admin-logs-cache-contract.php` queda auto-descubierto por la suite PHP 8.5 de contratos.
- Pendiente en este snapshot: `BRVTAL CI / validate`, revisión advisory de CodeRabbit y análisis automático de SonarQube Cloud sobre el head final.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobar el deploy real y la superficie correspondiente.

## Qué sigue

1. Resolver en esta misma rama cualquier finding válido de CodeRabbit, SonarQube Cloud o BRVTAL CI.
2. Hacer squash merge solo con `BRVTAL CI / validate` verde.
3. Verificar `BRVTAL CI / validate` del SHA exacto resultante en `main`.
4. Continuar con el siguiente hallazgo priorizado de la auditoría global.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de validación: `docs/TESTING.md`.
- Issue abordado: `#372`.
