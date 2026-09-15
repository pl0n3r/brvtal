# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- La navegación de retorno de las páginas públicas canónicas deja de construir `/#<route_type>` indiscriminadamente.
- Events, Artists y Sets conservan retorno contextual a sus secciones reales del Home: `/#events`, `/#artists` y `/#sets`.
- Releases, Blog y Pages regresan a `/` con `← BACK HOME`, evitando anchors inexistentes como `/#releases`, `/#blog` o `/#pages`.
- La lógica queda centralizada en el renderer público y no altera enlaces canónicos, relaciones ni CTAs externos.

## Archivos modificados en este deploy

- `config/public_page.php` — decide el destino de retorno según las secciones públicas que realmente existen.
- `tests/public-entity-pages-contract.php` — contrato que impide reintroducir anchors derivados ciegamente del tipo de ruta.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `053846ae8e5fba89c78563d9dc460288fec6b3ce`, cuyo ciclo post-merge exacto pasó fast, database, Chromium, WebKit, real-stack y validate.
- El cambio está acotado al renderer de páginas canónicas y su contrato estático.
- No se usa Work ni existe runner local en este flujo; la ejecución automatizada queda a cargo de los gates del PR.
- Pendiente de **BRVTAL CI / validate** y **PHP 8.5 Compatibility / php85** del PR.
- Tras el merge se verificará la matriz completa sobre el SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Abrir el PR y ejecutar los gates path-aware.
2. Corregir en la misma rama cualquier fallo detectado.
3. Con CI verde, hacer squash merge y verificar la matriz completa sobre el SHA exacto de `main`.
4. Cerrar #210 y continuar serialmente con #196.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
