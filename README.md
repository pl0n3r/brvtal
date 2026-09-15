# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Media Register ya no acepta rutas locales `/uploads/...` inexistentes: resuelve el archivo dentro del uploads root, deriva MIME/tamaño desde bytes reales y rechaza discrepancias entre el tipo declarado y el archivo.
- Las URLs externas de Media mantienen su semántica remota; no se intenta convertirlas en archivos locales.
- El exportador de backups pagina cada tabla con un `ORDER BY` explícito: usa la PK (incluida PK compuesta) cuando existe y, si no existe, ordena por todas las columnas del registro.
- El recovery rehearsal aislado fuerza una tabla de 620 filas para atravesar tres chunks y comprueba conteo, identidades y filas en los límites 250/251 y 500/501 después del restore.
- Producción sigue sin exponer restore automático ni acciones destructivas nuevas.

## Archivos modificados en este deploy

- `api/media-library.php` — valida existencia, containment, MIME, tipo y tamaño al registrar Media local existente.
- `config/backups.php` — añade orden determinista a la paginación del dump SQL.
- `tests/media-library-contract.php` — fija el contrato de validación de Media Register local.
- `tests/backups-contract.php` — impide volver a paginación de backup sin `ORDER BY`.
- `tests/integration/backup-recovery-rehearsal.php` — verifica recuperación exacta de 620 filas multi-chunk en MariaDB aislado.
- `tests/backup-recovery-rehearsal-contract.php` — exige la evidencia multi-chunk y mantiene las barreras de seguridad del rehearsal.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Pendiente de los gates del PR: sintaxis/contratos de BRVTAL CI, PHP 8.5 Compatibility y Backup Recovery Rehearsal.
- El rehearsal usa exclusivamente bases `brvtal_test_*` efímeras y no consume credenciales ni URLs de producción.
- Tras el merge se verificará BRVTAL CI y PHP 8.5 Compatibility sobre el SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Resolver en un PR separado #296–#297: contrato JSON y locale English-first de CMS Pages.
2. Diseñar una solución atómica para #294 sin introducir locks parciales o una falsa garantía referencial en Media deletion.
3. Continuar el triage por integridad/severidad después de cerrar estos dos Issues.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
