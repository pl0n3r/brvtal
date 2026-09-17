# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

## Qué se hizo

- Google Tag Manager pasa a cargar automáticamente en todas las páginas públicas cuando existe un `GTM-...` válido; ya no depende de un botón de aceptación.
- Se elimina la UI pública `ANALYTICS SETTINGS / ALLOW ANALYTICS` y su CSS asociado.
- La medición BRVTAL (`page_view`, secciones, scroll, navegación, acciones declarativas y outbound) empieza desde la primera carga.
- `analytics_storage` inicia en `granted`; `ad_storage`, `ad_user_data` y `ad_personalization` siguen en `denied` desde el bootstrap de BRVTAL.
- DISCADMIN Settings y la documentación dejan de describir GTM como consent-gated.
- Los tests PHP y Playwright se ajustan para exigir carga inmediata y ausencia de la antigua compuerta de consentimiento.

## Archivos modificados en este deploy

- `AGENTS.md` — registra la política durable de carga inmediata de GTM.
- `README.md` — snapshot exacto del deploy.
- `config/public_analytics.php` — deja de servir el stylesheet de la antigua UI de consentimiento.
- `css/public-analytics.css` — eliminado; ya no existe UI pública de aceptación de Analytics.
- `discadmin/settings-v2.js` — Settings explica la política real de carga inmediata.
- `docs/ANALYTICS-MEASUREMENT.md` — actualiza el contrato de medición y GTM → GA4.
- `js/public-analytics.js` — bootstrap inmediato de GTM y Consent Mode para Analytics.
- `js/public-measurement.js` — elimina la compuerta basada en `localStorage` y mide desde primera carga.
- `tests/e2e/discadmin-settings-v2.spec.mjs` — actualiza la expectativa de Settings a la política de carga inmediata.
- `tests/e2e/public-analytics.spec.mjs` — verifica petición inmediata a GTM y ausencia de UI de consentimiento.
- `tests/e2e/public-measurement.spec.mjs` — verifica medición inmediata, incluso ante valores legacy de rechazo.
- `tests/public-analytics-contract.php` — contrato PHP actualizado para bootstrap inmediato.
- `tests/public-measurement-contract.php` — contrato de medición actualizado sin acceptance gate.

## Validación

- Base: `main` `b39de5517a4ad2a105347ebf739fda6cc670052e`, con BRVTAL CI #697 verde.
- El cambio debe pasar BRVTAL CI y revisión automatizada antes del squash merge.
- CI verde significa **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Después del merge, comprobar en producción que `gtm.js?id=GTM-W23PHGJG` aparece sin interacción del visitante y que Tag Assistant detecta el contenedor.
- Continuar #427: mapear los eventos `brvtal_*` dentro de GTM/GA4 y ampliar medición estructurada de Events, Artists, Sets, Memories y Contact.
