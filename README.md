# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Los uploads físicos nuevos de Media Library nacen en estado `draft` en vez de publicarse automáticamente.
- Los assets draft siguen disponibles dentro de DISCADMIN y en los pickers editoriales para preparar Events, Releases, Blog, Pages u otros contenidos antes de su publicación.
- La colección pública de Media mantiene su allowlist actual: solo registros con `status='published'` son elegibles para entrega pública.
- La publicación del asset sigue siendo una decisión editorial explícita desde el inspector de Media Library; publicar una entidad que lo referencia no publica automáticamente el Media global.
- No se modifican assets existentes ni estados ya persistidos.

## Archivos modificados en este deploy

- `api/media-library.php` — cambia el estado inicial del upload físico de `published` a `draft`.
- `tests/media-library-contract.php` — fija la política de draft por defecto, el filtro público `published` y el control explícito de publicación en DISCADMIN.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `1949f203b228f90e942ca66efe375522c9b3721d`.
- Ese SHA exacto tenía BRVTAL CI completo, PHP 8.5 Compatibility y Backup Recovery Rehearsal en success.
- No hay cambios de esquema, migraciones ni mutaciones de datos de producción.
- Los Media existentes conservan su estado actual; la nueva política aplica únicamente a uploads futuros.
- Pendiente de **BRVTAL CI / validate**, **PHP 8.5 Compatibility / php85** y **Backup Recovery Rehearsal** del PR.
- Tras el merge se verificará la matriz completa sobre el SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir en esta misma rama cualquier fallo detectado.
2. Con CI verde, hacer squash merge.
3. Verificar BRVTAL CI completo, PHP 8.5 y Backup Recovery sobre el SHA exacto resultante de `main`.
4. Confirmar #201 cerrado y continuar con el siguiente issue público prioritario vigente.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
