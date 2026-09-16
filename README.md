# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Las slides ocultas del Hero Slider quedan `inert`, por lo que sus CTAs ya no permanecen en el orden de foco; al activar una slide recupera interactividad.
- Las páginas canónicas de Events/Artists/Sets/Releases/Blog/Pages pasan por el mismo versionado de assets del Home, evitando CSS stale bajo cache immutable.
- El fallback estático deja de presentar Artists `href="#"` y acciones genéricas de SoundCloud como enlaces funcionales cuando la API pública no está disponible.
- Los enlaces externos reales de Sets reciben un nombre accesible específico con título + plataforma.
- Se añadieron regresiones PHP y Playwright para estos cuatro Quick Wins públicos.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo exacto de este deploy.
- `index.php` — versiona assets de páginas canónicas, neutraliza acciones fallback falsas e inyecta el runtime público versionado.
- `js/public-quick-wins.js` — sincroniza `inert` del Hero, nombres accesibles de Sets y seguridad semántica del fallback.
- `tests/public-quick-wins-contract.php` — protege contratos de versionado, fallback y accesibilidad.
- `tests/e2e/public-quick-wins.spec.mjs` — valida comportamiento de foco, placeholders y nombres accesibles en browser.

## Validación

- Base exacta: `main` `2fe3fb66c8c7147e677627dcdb9b404b72e13a8c`, con `BRVTAL CI / validate` verde.
- No había PRs abiertos al crear `fix/public-quick-wins`.
- Issues cubiertos: `#236`, `#251`, `#356`, `#358`.
- No hay migración de base de datos, cambios de schema ni mutación de datos de producción.
- Las correcciones se aplican en una capa pública pequeña en lugar de reescribir `js/app.js` o el runtime del Hero completo.
- Las regresiones nuevas quedan dentro de las suites automáticas PHP 8.5 + Chromium.
- Pendiente en este snapshot: `BRVTAL CI / validate`, revisión advisory de CodeRabbit y análisis automático de SonarQube Cloud sobre el head final.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobar el deploy real y la superficie correspondiente.

## Qué sigue

1. Resolver en esta misma rama cualquier finding válido de BRVTAL CI, CodeRabbit o SonarQube Cloud.
2. Hacer squash merge solo con `BRVTAL CI / validate` verde.
3. Verificar `BRVTAL CI / validate` del SHA exacto resultante en `main`.
4. Continuar con otro lote de varios Quick Wins compatibles antes de entrar en fixes de mayor profundidad.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de validación: `docs/TESTING.md`.
- Issues abordados: `#236`, `#251`, `#356`, `#358`.
