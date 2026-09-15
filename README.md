# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Content Core usa ahora exclusivamente el endpoint administrativo canónico `/api/index.php`; el API público deja de ser fallback de lecturas o mutaciones del admin.
- El helper compartido considera cualquier HTTP no-2xx como error aunque el body sea JSON parseable.
- Un payload administrativo con `ok:false` también se convierte en excepción antes de que un caller pueda mostrar feedback de éxito.
- Una respuesta no-JSON del API admin produce un error explícito y no dispara un segundo intento contra `/api/public`.
- Se conserva el redirect a `/discadmin/` ante HTTP 401, pero la llamada queda igualmente marcada como fallo.
- La regresión Playwright fuerza una mutación PUT con respuesta 502 no-JSON y un endpoint público 405 que declara `ok:true`; verifica que Content Core no hace ninguna petición al API público y no reporta guardado exitoso.
- No hay cambios de esquema, migraciones ni mutaciones de datos de producción.

## Archivos modificados en este deploy

- `discadmin/content-core.js` — elimina el fallback público y endurece el contrato HTTP/JSON del helper administrativo.
- `tests/e2e/content-core-api-boundary.spec.mjs` — cubre el falso guardado de #258 con una regresión funcional de browser.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `886c267fc87feec1d65e0b5644d7a52f18073ae6`.
- Ese SHA exacto tenía BRVTAL CI completo, PHP 8.5 Compatibility y Backup Recovery Rehearsal en success.
- No hay cambios de schema ni migraciones; la corrección está limitada al boundary de red de Content Core y su cobertura e2e.
- Pendiente de **BRVTAL CI / validate**, **PHP 8.5 Compatibility / php85**, **README Deploy Snapshot · PR** y **Backup Recovery Rehearsal** del PR.
- Tras el merge se verificará la matriz completa sobre el SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir en esta misma rama cualquier fallo detectado.
2. Con CI verde, hacer squash merge.
3. Verificar BRVTAL CI completo, PHP 8.5 y Backup Recovery sobre el SHA exacto resultante de `main`.
4. Confirmar #258 cerrado y continuar con el siguiente issue público prioritario vigente.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
