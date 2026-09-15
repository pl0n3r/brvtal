# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- El ETag del API público representa ahora únicamente el contenido real entregado, no una marca temporal generada en cada request.
- Se retiró `generated_at` del payload público porque era metadata volátil sin consumidor público y hacía que representaciones equivalentes tuvieran validators distintos.
- El cálculo de ETag quedó centralizado en `brvtal_public_etag()` usando exactamente el payload estable que se entrega dentro del envelope de éxito.
- Payloads idénticos conservan el mismo ETag; un cambio real en Events/Settings u otra parte del payload produce un ETag diferente.
- `If-None-Match` puede volver a producir 304 entre requests sin cambios de contenido, preservando el caching condicional existente.
- No hay cambios de esquema, migraciones ni mutaciones de datos de producción.

## Archivos modificados en este deploy

- `api/public-response.php` — añade el helper canónico para calcular ETags deterministas del payload público.
- `api/public.php` — elimina `generated_at` y usa el helper estable antes de evaluar `If-None-Match`.
- `tests/api-contract.php` — cubre estabilidad/invalidation del ETag e impide reintroducir metadata temporal al payload.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `7d669b137f238715403731fe3fed395e0bfdfcec`.
- Ese SHA exacto tenía BRVTAL CI completo, PHP 8.5 Compatibility y Backup Recovery Rehearsal en success.
- No hay cambios de schema ni migraciones; la corrección se limita al caching condicional del API público.
- Pendiente de **BRVTAL CI / validate**, **PHP 8.5 Compatibility / php85**, **README Deploy Snapshot · PR** y **Backup Recovery Rehearsal** del PR.
- Tras el merge se verificará la matriz completa sobre el SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir en esta misma rama cualquier fallo detectado.
2. Con CI verde, hacer squash merge.
3. Verificar BRVTAL CI completo, PHP 8.5 y Backup Recovery sobre el SHA exacto resultante de `main`.
4. Confirmar #209 cerrado y continuar con el siguiente issue público prioritario vigente.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
