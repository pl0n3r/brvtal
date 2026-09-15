# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- El health check público deja de exponer versiones exactas de PHP y MariaDB/MySQL, así como el driver de base de datos.
- `/api/health.php` conserva únicamente señales operativas públicas: estado, disponibilidad genérica de DB, deployment, timestamp y latencia.
- El límite común de `json_response()` reconoce también `/api/health` y `/api/index.php/health` y elimina `driver`, `server` y `php` antes de serializar cualquier respuesta pública de health.
- Las superficies autenticadas siguen pudiendo mostrar diagnóstico técnico detallado; el hardening está restringido a las rutas públicas de health.
- La trazabilidad pública de deployment se mantiene deliberadamente en el endpoint standalone.

## Archivos modificados en este deploy

- `api/health.php` — elimina lectura/exposición explícita de versiones y driver en respuestas healthy/degraded.
- `config/public_health.php` — nuevo helper puro para reconocer rutas públicas de health y sanear su payload.
- `config/bootstrap.php` — aplica el saneamiento justo antes de emitir JSON únicamente para rutas públicas de health.
- `tests/api-contract.php` — cubre aliases de health, eliminación de fingerprinting y preservación de señales operativas.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `45ea63ce0f476e17023bc7e2b12a420aa6da205b`.
- Ese SHA exacto tenía BRVTAL CI completo, PHP 8.5 Compatibility y Backup Recovery Rehearsal en success.
- El workflow Production Performance posterior a ese SHA falló en `Verify production connectivity` antes de ejecutar mediciones; se trata como evidencia operativa separada y no como validación de este cambio.
- No hay cambios de esquema, migraciones ni mutaciones de datos de producción.
- Pendiente de **BRVTAL CI / validate** y **PHP 8.5 Compatibility / php85** del PR.
- Tras el merge se verificará la matriz completa sobre el SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir en esta misma rama cualquier fallo detectado.
2. Con CI verde, hacer squash merge.
3. Verificar BRVTAL CI completo, PHP 8.5 y Backup Recovery sobre el SHA exacto resultante de `main`.
4. Cerrar #249 y continuar con el siguiente issue público prioritario vigente.
5. Mantener separado el seguimiento del fallo de conectividad de Production Performance.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
