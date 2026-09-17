# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

## Qué se hizo

- Se añade la **capa pública de medición compartida** de BRVTAL sobre `dataLayer`, sin crear un sistema paralelo a Google Tag Manager.
- La medición queda estrictamente ligada al mismo consentimiento de Analytics: sin opt-in no se emiten eventos `brvtal_*`.
- Se instrumentan de forma base los `page_view`, vistas de secciones Home, profundidad de scroll 25/50/75/90, apertura/cierre del menú, navegación del header/menu y enlaces salientes.
- La aceptación explícita de Analytics puede registrarse después del opt-in; rechazo inicial y revocación no se transmiten como telemetría nueva.
- Se incorpora una API pública mínima `window.BRVTALMeasure.push(...)` y hooks declarativos `data-measure-*` con allowlist de parámetros para evitar capturar texto/valores arbitrarios o PII.
- URLs externas se registran sin query string; el runtime evita eventos de alta frecuencia como `mousemove` o scroll por píxel.
- Se documenta el contrato BRVTAL → `dataLayer` → GTM → GA4 y la guía inicial de mapeo, dejando la instrumentación de Events/Artists/Sets/Memories/Contact y el dashboard GA4 como slices posteriores de #427.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy.
- `config/public_analytics.php` — entrega el runtime de medición junto al bootstrap consent-gated de GTM.
- `docs/ANALYTICS-MEASUREMENT.md` — documenta eventos, parámetros permitidos, privacidad, consentimiento y mapeo GTM → GA4.
- `js/public-analytics.js` — publica eventos internos de readiness/settings/aceptación para sincronizar la medición con el consentimiento canónico.
- `js/public-measurement.js` — implementa la capa compartida de medición pública, deduplicación básica y delegación de interacciones.
- `tests/e2e/public-measurement.spec.mjs` — cubre silencio sin consentimiento, señales normalizadas y arranque de medición al aceptar Analytics.
- `tests/public-measurement-contract.php` — protege carga, eventos base, allowlist y ausencia de recolección arbitraria de datos.

## Validación

- Base: `main` `07e7c75a217ab54cb18245bb4152dce0fa48e1f1`.
- BRVTAL CI y revisión automatizada deben quedar verdes antes del squash merge.
- CI verde significa **VALIDATED IN CODE**, no validación de producción.
- Este slice no configura tags dentro de GTM ni activa el futuro dashboard GA4 de DISCADMIN; esos pasos siguen dependiendo de configuración externa/credenciales y trabajo posterior de #427.

## Qué sigue

- Cerrar findings válidos de review, obtener CI verde, hacer squash merge y verificar el CI del SHA exacto de `main`.
- Continuar #427 con instrumentación estructurada de Events/tickets, Roster/Artists, Sets, Memories y Contact antes de construir reporting server-side con Google Analytics Data API.
