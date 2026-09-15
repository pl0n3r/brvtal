# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Se protege el editor canónico de Events para que un guardado no pueda reemplazar el lineup mientras la participación todavía está cargando o quedó en error.
- La hidratación de lineup ahora usa política **latest-wins**, evitando que una respuesta tardía de otro Event vuelva a pintar relaciones obsoletas.
- Al guardar participación existente se preservan `role` y `lineup_order`; editar fecha, venue u otros campos del Event ya no debe borrar roles ni reconstruir el running order según el catálogo global de Artists.
- Los Artists recién añadidos al lineup se anexan después del mayor `lineup_order` existente sin renumerar relaciones previas.
- Se añadió cobertura Playwright para la ventana de hidratación, preservación de metadata y carrera entre dos Events.
- No hay cambios de esquema, migraciones ni acciones sobre datos de producción.

## Archivos modificados en este deploy

- `discadmin/content-core.js` — añade estado/token de hidratación del lineup y preservación de metadata relacional al guardar.
- `tests/e2e/discadmin-content-core-lineup-integrity.spec.mjs` — regresiones de guardado temprano, `role`, `lineup_order` y latest-wins.
- `README.md` — snapshot operativo exacto de este deploy.

## Validación

- Base de trabajo: `main` `8da56d9db304e951c8117a393b300c864e77e4ec`, con **BRVTAL CI / validate** exacto en verde antes de abrir este batch.
- Los defectos #181, #230 y #231 estaban confirmados por inspección del flujo canónico de Content Core en `main`.
- Pendiente: **BRVTAL CI / validate** del PR y, tras squash merge, validación del nuevo SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica validación del editor autenticado en producción.

## Qué sigue

1. Ejecutar el CI path-aware del PR y corregir cualquier regresión en esta misma rama.
2. Con `validate` verde, hacer squash merge y comprobar el SHA exacto nuevo de `main`.
3. Continuar con el siguiente hallazgo abierto de DISCADMIN sin duplicar trabajo.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- Issues cubiertos: `#181`, `#230`, `#231`.
