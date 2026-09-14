# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. Se reemplaza en cada deploy. El contexto durable vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- Se estabiliza el arranque autenticado de deep-links de DISCADMIN después de hacer persistente `?module=<destino>`.
- Cuando existe un deep-link visible y la autenticación responde antes de que termine de cargar el shell, el bootstrap espera la capa final de Information Architecture y le entrega la restauración.
- Artists, Pages, Theme Studio y demás destinos nativos dejan de poder abrir Dashboard primero para corregirse después.
- Events conserva el camino canónico por el editor guiado de Content Core, sin exponer una vista legacy intermedia.
- Se mantiene el fallback actual si la capa de rutas no está disponible y Media continúa validando montaje directo de sesión restaurada.

## Archivos modificados en este deploy

- `discadmin/totp-login.js` — coordina la restauración autenticada con la capa final de rutas cuando existe un deep-link visible.
- `tests/e2e/discadmin-initial-media.spec.mjs` — reproduce auth inmediata antes de cargar IA y exige que un deep-link nativo nunca pase por Dashboard; conserva la regresión de Media inicial.
- `README.md` — snapshot operativo de este deploy.

## Validación

- El PR debe pasar `BRVTAL CI / validate`, `PHP 8.5 Compatibility / php85` y el gate de README antes del merge.
- Chromium debe demostrar que auth inmediata + `?module=artists` produce una única navegación a Artists y cero navegaciones a Dashboard, incluso cuando IA se carga después de iniciar `restoreSession()`.
- La regresión existente debe seguir demostrando que `?module=media` monta Media en la primera navegación autenticada.
- Después del merge se verificará el CI completo del SHA exacto de `main` y el deploy exacto en Hostinger.
- CI verde implica **VALIDATED IN CODE**; observar el SHA exacto en Hostinger implica **DEPLOYED**.

## Qué sigue

1. Continuar simplificando DISCADMIN solo ante fricciones reproducibles del shell/workspace, manteniendo ONE SHELL / ONE SIDEBAR / ONE SESSION / ONE CENTRAL WORKSPACE.
2. Mantener #122–#125 abiertos hasta ejecutar sus workflows autenticados y obtener evidencia real de producción.
3. No extender Media hacia CONNECTED mientras Media no tenga relaciones estructuradas públicas propias.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
