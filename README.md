# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Theme Studio V2 deja de convertir fallos de lectura en un theme por defecto editable: **Settings** y **Media** se consultan de forma independiente y Settings pasa a ser una fuente autoritativa obligatoria.
- Si Settings falla, Theme Studio muestra `SETTINGS UNAVAILABLE`, bloquea edición/activación y ofrece retry en vez de permitir sobrescribir configuración persistida desde un fallback generado.
- Si únicamente falla Media Library, el theme real sigue cargando, se preservan los assets actuales y solo se deshabilita temporalmente la selección de nuevos assets.
- Los cambios sin guardar de Theme Studio quedan protegidos al navegar a otro módulo, entrar a Technical/System Status o recargar/cerrar la pestaña.
- El branding del theme activo sincroniza ahora el logo principal, la variante mobile, los `<source>` responsive y el overlay `.hero-logo-glitch`, evitando que reaparezca el logo BRVTAL hard-coded encima de un logo administrado.
- Favicon y preloader branding usan el asset configurado y los assets locales admiten versionado con el SHA público para evitar caché stale.
- El runtime público ya no aborta toda la experiencia cuando falla un script local: core y enhancements cargan de forma resiliente, el fallo se marca como `degraded` y el HTML estático se revela aunque un módulo no cargue.
- El preloader tiene un failsafe explícito: un fallo de boot o de un módulo local nunca debe poder cubrir permanentemente la Home.
- Chromium cubre ahora un fallo realista de `menu-scroll-lock.js`, comprueba que los módulos posteriores continúan y que el fallback estático queda visible.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo exacto de este deploy.
- `discadmin/index.php` — carga versionada del guard de confiabilidad de Theme Studio dentro del shell canónico.
- `discadmin/theme-studio-reliability.css` — estados `SETTINGS UNAVAILABLE` y `MEDIA UNAVAILABLE`, incluido responsive/Light.
- `discadmin/theme-studio-reliability.js` — lectura independiente de Settings/Media, bloqueo seguro, retry y guard de cambios sin guardar.
- `js/public-runtime-loader.js` — carga resiliente de módulos locales y fallback estático anti-preloader bloqueado.
- `js/public-theme-branding-sync.js` — sincroniza logo responsive/glitch, favicon y preloader con el branding activo.
- `tests/e2e/public-mobile-performance.spec.mjs` — regresión Chromium para branding runtime y fallo de un core script local.
- `tests/theme-studio-reliability-contract.php` — contrato de seguridad de carga, dirty state, branding y graceful degradation.

## Validación

- Base de trabajo: `main` `6bbbbfb9d9e40a1b09fa743d5beebe12f90860b0`.
- Ese SHA tenía BRVTAL CI exacto en verde y Production Performance exitoso, incluido `Wait for exact Hostinger deploy`.
- No había PRs abiertos al iniciar este bloque.
- Los Issues fueron contrastados de nuevo contra el código actual antes de desarrollar; no se reutilizaron números desde resúmenes antiguos sin verificación.
- Se preserva ONE SHELL / ONE SIDEBAR / ONE SESSION / ONE CENTRAL WORKSPACE.
- La corrección no ejecuta arbitrary custom code ni amplía la superficie pública de Settings.
- Pendiente: CI del head final del PR y, después del squash merge, CI + Production Performance del SHA exacto de `main`.
- CI verde significará **VALIDATED IN CODE**. La apariencia exacta del theme activo y una prueba manual autenticada de navegación seguirán siendo validación de producción separada.

## Qué sigue

1. Ejecutar CI del PR, corregir cualquier regresión y hacer squash merge si `validate` queda verde.
2. Confirmar deploy exacto en Hostinger para el nuevo SHA de `main`.
3. Continuar la auditoría diagnóstica sobre el `main` desplegado, priorizando integridad editorial, publicación, navegación y seguridad.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Bugs abordados por este bloque: `#190`, `#266`, `#352`, `#357`.
