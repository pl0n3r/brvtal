# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Los helpers públicos de URL ahora rechazan `null`, cadenas vacías y valores compuestos solo por espacios antes de construir `new URL(...)`.
- Home deja de convertir tickets, enlaces de Sets o redes sociales vacías en enlaces falsos hacia la página actual.
- Archive deja de mostrar `TICKETS ↗` cuando el evento no tiene una URL editorial real.
- CONNECTED aplica el mismo invariante a enlaces opcionales de Sets y Releases, evitando `OPEN PLATFORM ↗` o `LISTEN ↗` falsos.
- Los valores HTTP(S) reales siguen admitiendo espacios accidentales alrededor y se normalizan después de `trim()`.
- Se añade una regresión Playwright única para Home, Archive y CONNECTED.

## Archivos modificados en este deploy

- `js/app.js` — rechaza URLs opcionales vacías antes de resolverlas contra `location.href`.
- `js/archive.js` — aplica el mismo guard a tickets del runtime de Archive.
- `js/related-content.js` — aplica el mismo guard a CTAs externos opcionales de CONNECTED.
- `tests/e2e/public-empty-url-guards.spec.mjs` — cubre links vacíos/whitespace y un HTTP(S) real normalizado.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `aa7b78dabfda205aae7acad3dbd689fe42f8f95f`, cuyo ciclo completo post-merge estaba verde antes de iniciar este trabajo.
- El diff funcional permanece acotado: 8 líneas cambiadas en `app.js`, 4 en `archive.js` y 6 en `related-content.js`, además de la nueva regresión E2E.
- No se usa Work ni existe runner local en este flujo; la ejecución automatizada queda a cargo de los gates del PR.
- Pendiente de **BRVTAL CI / validate** y **PHP 8.5 Compatibility / php85** del PR.
- Tras el merge se verificará la matriz completa sobre el SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Abrir el PR y ejecutar los gates path-aware, incluido Chromium para la nueva regresión.
2. Corregir en la misma rama cualquier fallo detectado por CI.
3. Con CI verde, hacer squash merge y verificar la matriz completa sobre el SHA exacto de `main`.
4. Cerrar #291 con el merge y continuar con el siguiente hallazgo abierto de Public Discovery según `AGENTS.md`.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
