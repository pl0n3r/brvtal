# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Se corrigió la legibilidad de títulos largos en **NEXT EXPERIENCE** para móvil, reproduciendo el problema observado en iPhone.
- El título móvil usa una escala tipográfica menos agresiva, `line-height` seguro y tracking ligeramente más abierto para evitar que varias líneas se monten entre sí.
- El glitch móvil se mantiene como parte de la identidad visual, pero con desplazamiento y franja reducidos para no dominar el texto.
- Se añadió una regresión Playwright en viewport 390×844 que comprueba tamaño, interlineado, ancho y separación con el subtítulo siguiente.
- Escritorio conserva sus reglas actuales; no hay cambios de esquema, datos, permisos ni APIs.

## Archivos modificados en este deploy

- `css/style.css` — ajusta tipografía y glitch de NEXT EXPERIENCE dentro del breakpoint móvil.
- `tests/e2e/public-mobile-readability.spec.mjs` — añade cobertura del título largo en viewport tipo iPhone.
- `README.md` — snapshot operativo exacto de este deploy.

## Validación

- Base de trabajo: `main` `72ded3dc19e3b8ae1d349e152de4dfa7552815c9`, con **BRVTAL CI / validate** exacto en verde.
- El defecto estaba previamente **VALIDATED IN PRODUCTION / REAL DEVICE** mediante evidencia visual en iPhone.
- Pendiente: gates del PR y, tras squash merge, **BRVTAL CI / validate** sobre el nuevo SHA exacto de `main`.
- CI verde significará **VALIDATED IN CODE**; la corrección visual solo se declarará **VALIDATED IN PRODUCTION** después de comprobar el deploy real.

## Qué sigue

1. Ejecutar el CI path-aware del PR y corregir cualquier regresión en esta misma rama.
2. Con `validate` verde, hacer squash merge y comprobar el SHA exacto nuevo de `main`.
3. Confirmar en producción móvil que el título largo ya no se solapa y cerrar el Issue #255 con esa evidencia.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- Issue de origen: `#255`.
