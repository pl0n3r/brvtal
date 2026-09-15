# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Content Health, Global Search y Admin Activity conservan ahora el ID del registro seleccionado y abren el editor exacto dentro del shell canónico de DISCADMIN.
- La navegación exacta se centralizó en la capa compartida `content-core-nav.js`, reutilizando `go(...)`, los editores existentes y las superficies dinámicas de Releases, Blog y Media.
- Global Search reutiliza su evento existente después de navegar al módulo; Admin Activity reutiliza el `resource_id` ya disponible en la fila; Content Health expone explícitamente `type` e `id` en su acción OPEN.
- Los fallbacks siguen navegando al módulo canónico cuando no existe un editor exacto soportado; no se crean rutas ni mini-admins nuevos.
- Se añadieron regresiones de navegador para comprobar que Search, Activity y Content Health abren el ID esperado, no solo el módulo.
- No hay cambios de API, esquema, migraciones, permisos ni datos de producción.

## Archivos modificados en este deploy

- `discadmin/content-core-nav.js` — boundary compartido para navegar y revelar el registro administrativo exacto dentro del shell existente.
- `discadmin/content-health.js` — conserva `type` e `id` en cada acción OPEN para navegación exacta.
- `tests/e2e/discadmin-global-search.spec.mjs` — regresión de Global Search que exige abrir el Event ID seleccionado.
- `tests/e2e/discadmin-admin-activity.spec.mjs` — regresión de Admin Activity que exige abrir el `resource_id` auditado.
- `tests/e2e/discadmin-content-health-navigation.spec.mjs` — regresión específica para OPEN desde Content Health.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `8a29a206f87b8c63020484426ba461211bacd4b2`.
- Ese SHA exacto tenía BRVTAL CI completo —fast, database, Chromium, WebKit, real-stack y validate—, PHP 8.5 Compatibility y Backup Recovery Rehearsal en success.
- Pendiente de gates del PR y, tras el merge, matriz completa sobre el SHA exacto nuevo de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir cualquier fallo en esta misma rama.
2. Con CI verde, hacer squash merge y verificar la matriz completa sobre el nuevo SHA exacto de `main`.
3. Confirmar el cierre de #148, #170 y #172 y refrescar nuevamente todos los Issues abiertos.
4. Seleccionar el siguiente batch homogéneo de Quick Wins de bajo riesgo sin mezclar cambios de seguridad/integridad de mayor alcance.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
