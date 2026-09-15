# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Global Search dejó de tratar los primeros seis resultados por tipo como si fueran el catálogo completo.
- La API ahora calcula el total real de coincidencias por grupo cuando una página puede estar truncada y expone `total`, `offset` y `has_more`.
- La carga progresiva se limita a un único tipo de contenido mediante `type` + `offset`, manteniendo el page size interno en seis resultados.
- La UI muestra claramente cuántos resultados están cargados frente al total real (`6 / 8`, por ejemplo) y ofrece `LOAD MORE` cuando quedan coincidencias ocultas.
- Los resultados cargados posteriormente conservan el mismo comportamiento de shortcut hacia el editor canónico del registro.
- Se añadió cobertura de contrato y Playwright para el caso de más de seis coincidencias sin crear registros artificiales en producción.

## Archivos modificados en este deploy

- `api/admin-search.php` — añade conteos exactos por grupo y paginación segura por tipo/offset.
- `discadmin/global-search.js` — muestra conteos cargados/total y permite cargar coincidencias adicionales.
- `tests/global-search-contract.php` — protege el contrato de total real, `has_more`, offset por tipo y `LOAD MORE`.
- `tests/e2e/discadmin-global-search.spec.mjs` — cubre la carga de coincidencias más allá de las primeras seis.
- `README.md` — snapshot operativo exacto de este deploy.

## Validación

- Base de trabajo: `main` `8563a29ed4fe3707ecea369378532baf6446745c`, con **BRVTAL CI / validate** exacto en verde antes de abrir este batch.
- El hallazgo #279 seguía presente en `main`: la API limitaba a seis resultados por tipo y la UI no distinguía ese subconjunto del total real ni ofrecía una vía para continuar.
- Pendiente: **BRVTAL CI / validate** del PR y, tras squash merge, validación del nuevo SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica validación interactiva autenticada en producción.

## Qué sigue

1. Ejecutar el CI path-aware del PR y corregir cualquier regresión en esta misma rama.
2. Con `validate` verde, hacer squash merge y comprobar el SHA exacto nuevo de `main`.
3. Continuar con el siguiente hallazgo abierto de DISCADMIN sin duplicar trabajo.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- Issue cubierto: `#279`.
