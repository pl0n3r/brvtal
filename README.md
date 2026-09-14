# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. Se reemplaza en cada deploy. El contexto durable vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- Se evita que una respuesta nativa obsoleta de DISCADMIN sobrescriba los datos o el workspace de un destino más reciente.
- Las lecturas iniciadas sincrónicamente por una navegación reciben el mismo token de ruta; si terminan después de que el usuario cambió de destino, se descartan antes de que el legacy escriba `state.rows` o renderice.
- El fence solo aplica a requests capturados dentro de una navegación; mutaciones y requests ordinarios conservan su comportamiento actual.
- La protección dinámica previa de Media, Releases y Blog permanece intacta.
- No hay cambios de API, base de datos ni contratos de publicación.

## Archivos modificados en este deploy

- `discadmin/admin-information-architecture.js` — añade un fence de requests ligado al token de navegación y usa el mismo coordinador para rutas nativas y Events / Content Core.
- `tests/e2e/discadmin-information-architecture.spec.mjs` — fuerza Artists lento → Pages rápido y exige que la respuesta tardía de Artists no cambie section, rows, render ni URL; conserva la regresión dinámica Releases → Media.
- `README.md` — snapshot operativo de este deploy.

## Validación

- La rama debe pasar sintaxis JavaScript y la regresión dirigida de Information Architecture antes del merge.
- El PR debe pasar `BRVTAL CI / validate`, `PHP 8.5 Compatibility / php85` y el gate de README.
- Chromium debe demostrar que, tras Pages, una respuesta tardía de Artists no puede reescribir los rows ni renderizar Artists.
- Después del merge se verificará el CI completo del SHA exacto de `main` y el deploy exacto en Hostinger.
- CI verde implica **VALIDATED IN CODE**; observar el SHA exacto en Hostinger implica **DEPLOYED**.

## Qué sigue

1. Continuar simplificando DISCADMIN solo ante fricciones reproducibles del shell/workspace.
2. Mantener #122–#125 abiertos hasta ejecutar sus workflows autenticados y obtener evidencia real de producción.
3. No extender Media hacia CONNECTED mientras Media no tenga relaciones estructuradas públicas propias.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
