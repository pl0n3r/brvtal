# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Backups amplía a 44 px los targets móviles de creación, retry, refresh y descargas.
- Blog conserva visible el estado editorial de cada post en listados menores de 700 px.
- System Status amplía a 44 px las acciones operativas en móvil.
- Una regresión Playwright mide los hit areas y comprueba la visibilidad del status de Blog a viewport móvil.
- No hay cambios de API, esquema, migraciones ni datos de producción.

## Archivos modificados en este deploy

- `discadmin/backups.css` — targets táctiles móviles consistentes para acciones y descargas.
- `discadmin/blog.css` — conserva status editorial en el layout móvil.
- `discadmin/system-status-v2.css` — targets táctiles móviles para acciones de diagnóstico.
- `tests/e2e/discadmin-mobile-quick-wins.spec.mjs` — regresiones browser de #269, #270 y #280.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `8cfe8c7bdda0188269a3ee3e2c0f9aaac781c80a`.
- Ese SHA exacto tenía BRVTAL CI completo, PHP 8.5 Compatibility y Backup Recovery Rehearsal en success.
- Pendiente de gates del PR y, tras el merge, matriz completa sobre el SHA exacto nuevo de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir cualquier fallo en esta misma rama.
2. Con CI verde, hacer squash merge.
3. Verificar full BRVTAL CI, PHP 8.5 y Backup Recovery sobre el SHA exacto resultante de `main`.
4. Continuar con la cola de quick wins del inventario de issues, priorizada por menor esfuerzo y riesgo.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
