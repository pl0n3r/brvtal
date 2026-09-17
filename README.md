# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí el snapshot exacto de lo que cambió y un panorama general actualizado de lo pendiente.

## Qué se hizo

- Corrige #260 en Media Library móvil/tablet apilado.
- Al tocar un asset, el inspector recién renderizado se revela inmediatamente mediante un desplazamiento explícito al panel de detalle.
- El desplazamiento solo ocurre en selecciones iniciadas por el usuario y hasta 1050 px; desktop conserva su posición.
- Refresh, upload, regeneración y otras recargas internas de la selección no fuerzan saltos de viewport.
- La regresión Playwright cubre selección móvil, ausencia de salto en refresh interno y comportamiento desktop.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente.
- `discadmin/media-library.js` — revela el inspector tras una selección manual en layout apilado.
- `tests/e2e/discadmin-media-mobile-inspector.spec.mjs` — regresiones móvil/desktop y protección contra saltos en refresh.

## Validación

- Base exacta: `main` `58d92fdf5ab75c5995a3d7f1c5edcc8a4a9f797c`.
- Ese SHA exacto pasó BRVTAL CI #862 y queda **VALIDATED IN CODE**.
- El head del PR debe pasar BRVTAL CI, SonarCloud y CodeRabbit antes del squash merge.
- Tras el merge se verificará BRVTAL CI sobre el SHA exacto resultante de `main`.
- CI verde significa **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Cerrar gates exactos de #260, corregir findings válidos, squash merge y verificar el nuevo `main`.
- Continuar con #388: RESET LOG seguro desde System Status reutilizando el boundary existente.
- Después atacar #365: Dashboard V2 como superficie autoritativa de Health/Activity con navegación al registro exacto.
- Mantener #451 como burn-down continuo con cambios pequeños y revalidados.

## Panorama general pendiente

- **Sonar / calidad:** #451 continúa por riesgo y área, con PRs pequeños.
- **System Status:** #388 — RESET LOG autenticado, con CSRF y confirmación.
- **Dashboard / DISCADMIN:** #365, #348 y #351 — autoridad del Dashboard, IA y Theme Studio.
- **Settings / Theme:** #191 — integridad referencial de `theme.active`.
- **Hero Slider:** #237 y #221 — breakpoint responsive e integridad de media.
- **Apariencia:** #149 — completar Light en módulos modernos.
- **Seguridad editorial / navegación:** #257, #216, #174 y #193.
- **SEO editorial:** #182, #214, #204 y #272 antes de #390.
- **Content / edición:** #224 y #252 — ticket types e integridad de media.
- **Activity / operaciones:** #232 y #195 — historial navegable y cobertura de audit log.
- **Bulk Actions:** #275 — resolver catálogos mayores de 500 sin truncado silencioso.
- **Archivo cultural:** #398 / #403 — Event Records y relaciones estructuradas.
- **Memories:** #415 / PR #434 requiere reconciliación con `main`; ninguna migración de producción automática.
- **Idioma:** #212 — ES canónico + EN automático por fases.
- **Backups:** #389 — scheduling seguro y Drive opcional con autorización externa.
