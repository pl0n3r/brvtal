# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Los listados públicos del Home enlazan ahora Events, Artists y Sets con sus páginas canónicas internas cuando existe un slug válido.
- El título de cada Event abre `/events/{slug}` sin retirar el CTA externo de tickets.
- Cada Artist usa `/artists/{slug}` como destino principal del elemento `PROFILE`, en lugar de sustituir el perfil BRVTAL por website/Instagram.
- El título de cada Set abre `/sets/{slug}` y el enlace externo de escucha continúa disponible como CTA secundario.
- Slugs vacíos o inválidos no generan rutas internas rotas.
- La mejora se carga como enhancement público separado y reutiliza los datos CMS ya compartidos por el runtime.
- El harness del runtime adaptativo conoce el nuevo enhancement y preserva el orden versionado en touch, reduced-motion, full-motion y fallback.

## Archivos modificados en este deploy

- `js/public-canonical-navigation.js` — conecta los listados Home con rutas canónicas de Events, Artists y Sets.
- `js/public-runtime-loader.js` — carga el enhancement de navegación canónica con la versión de deploy.
- `tests/e2e/public-canonical-navigation.spec.mjs` — regresión Playwright para enlaces internos, CTAs externos y slugs inválidos.
- `tests/e2e/public-mobile-performance.spec.mjs` — incorpora el nuevo enhancement al contrato de orden/carga del runtime adaptativo.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `2b32b5f3395b6c7e538d2fed3607c736a6522e4b`, con BRVTAL CI y PHP 8.5 verdes sobre ese SHA exacto antes de iniciar el cambio.
- El cambio no modifica contratos de datos ni elimina CTAs externos; añade únicamente navegación interna sobre slugs públicos válidos.
- El primer Chromium detectó que el harness de performance no mockeaba el nuevo script; la regresión funcional nueva sí había pasado. Se corrigió el contrato del loader en esta misma rama.
- No se usa Work ni existe runner local en este flujo; la ejecución automatizada queda a cargo de los gates del PR.
- Pendiente de **BRVTAL CI / validate** y **PHP 8.5 Compatibility / php85** del head corregido.
- Tras el merge se verificará la matriz completa sobre el SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Revalidar el PR con Chromium y los gates path-aware.
2. Con CI verde, hacer squash merge y verificar la matriz completa sobre el SHA exacto de `main`.
3. Cerrar #240 y continuar serialmente con #210.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
