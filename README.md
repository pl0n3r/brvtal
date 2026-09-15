# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- System Status legacy ya no declara `HEALTHY` cuando existe un check `DEGRADED`, `READ-ONLY` o `UNKNOWN`; esos estados producen un resumen global `DEGRADED`.
- System Status v2 deja de convertir fallos de Content Health y Admin Activity en score `0`, contadores `0` o una lista legítimamente vacía.
- Cuando una subfuente falla, su panel muestra `UNAVAILABLE`, el resumen pasa a `DEGRADED` y `ATTENTION REQUIRED` incorpora una señal explícita con el error de la fuente.
- El botón global `REFRESH` sigue siendo la vía de retry y Advanced Diagnostics conserva el estado de overview, Content Health y Activity para diagnóstico read-only.
- Se añadieron regresiones para el resumen legacy y para fallos parciales de Content Health/Activity en System Status v2.
- No hay cambios de API, base de datos, esquema, migraciones, permisos ni datos de producción.

## Archivos modificados en este deploy

- `discadmin/status.php` — clasificación global fail-visible para estados warning/degraded.
- `discadmin/system-status-v2.js` — estados `UNAVAILABLE`, degradación parcial y señales de diagnóstico para subfuentes fallidas.
- `tests/e2e/discadmin-system-status-degraded.spec.mjs` — regresiones para #161 y #256.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `29ad6620d1b03b331debd8201e3306bc6bcbf29a`.
- Ese SHA exacto tenía BRVTAL CI completo —fast, database, Chromium, WebKit, real-stack y validate—, PHP 8.5 Compatibility y Backup Recovery Rehearsal en success.
- Pendiente de gates del PR y, tras el merge, matriz completa sobre el SHA exacto nuevo de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir cualquier fallo en esta misma rama.
2. Con CI verde, hacer squash merge y verificar la matriz completa sobre el nuevo SHA exacto de `main`.
3. Confirmar el cierre de #161 y #256 y refrescar nuevamente todos los Issues abiertos.
4. Continuar con el siguiente batch pequeño de integridad/fiabilidad, manteniendo #332 separado como incidencia operativa de Production Performance.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
