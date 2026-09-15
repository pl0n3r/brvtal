# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- El editor de Blog ahora distingue una fuente relacionada realmente vacía de una fuente que falló al cargar.
- Si Events, Artists, Sets o Releases falla temporalmente, el editor muestra una advertencia explícita en lugar de presentar ese origen como vacío.
- Al guardar una edición, las relaciones existentes pertenecientes a una fuente fallida se conservan aunque no existan checkboxes disponibles para representarlas.
- Las relaciones existentes que siguen seleccionadas mantienen su orden editorial y las relaciones nuevas se anexan después, evitando reemplazos destructivos por una falla transitoria.
- Se añadió cobertura Playwright para preservar una relación Blog→Artist mientras Artists está caído y para diferenciar ese error de una respuesta válida con cero registros.
- No hay cambios de esquema, migraciones ni acciones sobre datos de producción.

## Archivos modificados en este deploy

- `discadmin/blog.js` — añade estado por fuente relacionada y combinación no destructiva de relaciones al guardar.
- `tests/e2e/discadmin-blog.spec.mjs` — cubre fallo parcial de fuentes, preservación relacional y estado vacío válido.
- `README.md` — snapshot operativo exacto de este deploy.

## Validación

- Base de trabajo: `main` `c8c3b85ffa3ce2ff2b9b67fdaa7a165cdfaaf8f4`, con **BRVTAL CI / validate** exacto en verde antes de abrir este batch.
- El hallazgo #200 estaba confirmado por inspección del flujo de Blog en `main`: un fallo de carga se convertía en `[]` y el guardado reconstruía `relations` únicamente desde checkboxes visibles.
- Pendiente: **BRVTAL CI / validate** del PR y, tras squash merge, validación del nuevo SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica validación funcional autenticada en producción.

## Qué sigue

1. Ejecutar el CI path-aware del PR y corregir cualquier regresión en esta misma rama.
2. Con `validate` verde, hacer squash merge y comprobar el SHA exacto nuevo de `main`.
3. Continuar con el siguiente hallazgo abierto de DISCADMIN sin duplicar trabajo.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- Issue cubierto: `#200`.
