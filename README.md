# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Theme Studio se reemplaza visualmente por una experiencia V2 dentro del mismo shell de DISCADMIN, organizada por Brand, Palette, Type, Navigation, Experience, SEO y Manage.
- Logo principal, logo móvil, favicon, logo de preloader y OG image se seleccionan visualmente desde Media con buscador, preview, clear y navegación accesible por teclado.
- El editor distingue claramente **Save Draft** de **Save & Activate**: guardar ya no activa el tema accidentalmente. También incorpora selector de temas, estado LIVE/NOT LIVE, cambios sin guardar, revert, duplicate y presets.
- Los controles V2 expuestos están limitados a propiedades que tienen mapping público seguro; valores legacy no expuestos se preservan al guardar en lugar de borrarse silenciosamente.
- El preview Desktop/Mobile usa los mismos grupos de branding, color y tipografía que el runtime público.
- El nuevo runtime público reutiliza `BRVTALPublicDataPromise` para aplicar el tema activo sin otra petición cuando el payload ya existe. Conecta branding, logo responsive, favicon, tokens de color, tipografía, header/menu, scene/sound visibility y efectos seguros.
- Analytics y custom code siguen fuera del payload ejecutable del runtime público.
- Se amplió el contrato Chromium del loader y se añadió un contrato PHP específico de Theme Studio V2.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo exacto de este deploy.
- `discadmin/index.php` — carga versionada de Theme Studio V2 dentro del shell canónico.
- `discadmin/theme-studio-v2.css` — layout responsive, preview y Media picker de Theme Studio V2.
- `discadmin/theme-studio-v2.js` — editor V2, lifecycle draft/activate, picker visual de Media y preview.
- `js/public-runtime-loader.js` — incorpora el runtime público de tema al pipeline versionado.
- `js/public-theme-runtime.js` — aplica el tema activo real al frontend público sin ejecutar analytics/custom code.
- `tests/e2e/public-mobile-performance.spec.mjs` — incluye Theme runtime en el contrato de orden/versionado del loader.
- `tests/theme-studio-v2-contract.php` — contrato de wiring, seguridad y comportamiento de Theme Studio V2.

## Validación

- Base de trabajo: `main` `0d635035e62f23b5752637db9f1e28eebbe7360d`.
- El SHA base tenía BRVTAL CI exacto en verde y Production Performance exitoso, incluido `Wait for exact Hostinger deploy`.
- Se confirmaron sin PRs abiertos antes de iniciar esta rama los Issues existentes #207, #217, #232, #234 y #235; no se abrió trabajo duplicado.
- La implementación mantiene ONE SHELL / ONE SIDEBAR / ONE SESSION / ONE CENTRAL WORKSPACE y no crea otro admin.
- Pendiente: CI path-aware del PR y, después del squash merge, CI + Production Performance del SHA exacto de `main`.
- CI verde significará **VALIDATED IN CODE**. La revisión visual final de Theme Studio y el resultado de un cambio de tema real siguen requiriendo validación en producción.

## Qué sigue

1. Ejecutar CI del PR, corregir cualquier regresión y hacer squash merge si `validate` queda verde.
2. Confirmar deploy exacto en Hostinger para el nuevo SHA de `main`.
3. Continuar inmediatamente con Dashboard V2 (#221) y luego retomar la auditoría diagnóstica.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Issues objetivo: `#207`, `#217`, `#232`, `#234`, `#235`.
