# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- La sección Contact ahora incorpora un formulario público responsive integrado con la estética BRVTAL.
- El formulario usa un CAPTCHA first-party firmado y validado en servidor, honeypot, validación server-side y rate limiting por origen sin depender de un servicio Node ni exponer secretos al navegador.
- El envío conserva el mensaje escrito cuando ocurre un error y comunica estados de carga, validación, rate limit, éxito o indisponibilidad.
- El destinatario del formulario se resuelve en servidor y puede configurarse con `contact.to` o `BRVTAL_CONTACT_TO`; el frontend no conoce el inbox interno.
- Instagram, SoundCloud, YouTube y Spotify se muestran como iconos SVG accesibles, alineados con el lenguaje visual del sitio y ocultos cuando no existe URL configurada.
- Se añadió cobertura de contrato para CAPTCHA/rate limit/validación, wiring del runtime, iconos accesibles y targets móviles; el contrato Chromium del loader ahora incluye el nuevo módulo Contact en todos los modos de motion.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo exacto de este deploy.
- `api/contact.php` — endpoint público GET/POST para challenge y envío protegido del formulario.
- `config/config.example.php` — documenta la configuración server-side del destinatario y corrige el origen canónico de ejemplo.
- `config/public_contact.php` — CAPTCHA firmado, validación, rate limiting y composición/envío del mensaje.
- `css/contact-social.css` — sistema visual responsive del formulario y rail de iconos sociales.
- `js/public-contact.js` — monta Contact, sincroniza redes sociales y gestiona challenge/envío/errores.
- `js/public-runtime-loader.js` — carga el enhancement de Contact dentro del runtime público versionado.
- `tests/e2e/public-mobile-performance.spec.mjs` — actualiza el contrato Chromium del runtime modular para incluir Contact.
- `tests/public-contact-contract.php` — contrato PHP del anti-bot, delivery metadata y contratos UI/runtime.

## Validación

- Base de trabajo: `main` `d21dc9ce25d081b08aa2d4451d7e2841278f384e`, con **BRVTAL CI / validate** exacto en verde y Production Performance exitoso antes de abrir esta rama.
- Los requisitos #205 y #349 estaban abiertos y confirmados; no se abrió trabajo duplicado.
- Dos iteraciones iniciales de un fixture Playwright aislado fallaron por su contexto artificial y se retiraron. El fallo Chromium restante reveló correctamente que el contrato existente del loader debía reconocer el nuevo enhancement; ese contrato fue actualizado, sin relajar comportamiento de producto.
- Pendiente: **BRVTAL CI / validate** del PR y, tras squash merge, validación del nuevo SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**. La recepción real de email y la composición visual final en producción requieren validación de producción después del deploy.

## Qué sigue

1. Ejecutar el CI path-aware del PR y corregir cualquier regresión en esta misma rama.
2. Con `validate` verde, hacer squash merge, confirmar el deploy exacto y validar Contact/redes en producción.
3. Continuar con Theme Studio/branding y luego Dashboard, antes de retomar la auditoría diagnóstica.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Issues cubiertos: `#205`, `#349`.
