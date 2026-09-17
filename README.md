# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

## Qué se hizo

- Google Tag Manager queda como **única capa pública de tags** de BRVTAL.
- `Settings → Analytics & Privacy` expone solo un `Google Tag Manager Container ID`; el campo GA4 directo se retira del flujo principal.
- El runtime público ya no carga `gtag.js` ni configura GA4 directamente.
- GTM se solicita únicamente después de consentimiento explícito del visitante; no se instala el bloque `noscript` para no romper el modelo consent-first.
- Antes de cargar GTM se encola Consent Mode con estado inicial denegado; la aceptación habilita solo `analytics_storage` y mantiene denegados `ad_storage`, `ad_user_data` y `ad_personalization`.
- Al revocar consentimiento se envía primero una actualización `denied`, se retira el loader de GTM y después se recarga la página para dejar el contenedor fuera de la siguiente navegación.
- Al guardar GTM se limpian las claves legacy de GA4/aliases conocidas sin tocar otras claves hermanas del JSON de Analytics.
- Se conserva compatibilidad de lectura con claves GTM legacy de Theme Studio mientras se migra al campo canónico `analytics.gtm_id`.
- Se actualiza la documentación durable para dejar GTM-only como arquitectura canónica y se amplían contratos PHP/Playwright para consentimiento, revocación y el editor tipado de Settings.

## Archivos modificados en este deploy

- `AGENTS.md` — registra GTM-only como decisión durable de arquitectura pública.
- `README.md` — snapshot exacto del deploy.
- `config/public_analytics.php` — valida/resuelve el Container ID de GTM y genera el bootstrap público consent-gated.
- `discadmin/settings-v2.js` — convierte Analytics en configuración GTM-only y retira GA4 directo del flujo normal.
- `docs/CONFIGURATION.md` — documenta propiedad, consentimiento y reglas de configuración de GTM.
- `index.php` — resuelve Analytics públicas mediante el Container ID canónico de GTM.
- `js/public-analytics.js` — carga `gtm.js` solo tras consentimiento y envía Consent Mode antes de carga/revocación.
- `tests/e2e/discadmin-settings-v2.spec.mjs` — alinea la regresión del editor tipado con el campo GTM y confirma que el campo GA4 directo ya no existe.
- `tests/e2e/public-analytics.spec.mjs` — cubre rechazo, aceptación, Consent Mode y revocación sin requests GTM prematuros.
- `tests/public-analytics-contract.php` — cubre validación, entrega GTM, lecturas seguras y contrato de consentimiento sin `gtag.js` directo.
- `tests/settings-control-plane-contract.php` — cubre el control plane GTM-only y la retirada del campo GA4.

## Validación

- Base: `main` `d95a2b6a6696ab59064126bc3f8f23ee48360473`.
- BRVTAL CI y revisión automatizada deben quedar verdes antes del squash merge.
- CI verde significa **VALIDATED IN CODE**, no validación de producción.
- La configuración persistida de producción sigue siendo independiente del deploy de código: el Container ID debe existir en `Settings → Analytics & Privacy` para que GTM se emita públicamente.

## Qué sigue

- Cerrar findings válidos de review, obtener CI verde, hacer squash merge y verificar el CI del SHA exacto de `main`.
- Tras el deploy, dejar `GTM-W23PHGJG` guardado como Container ID canónico en DISCADMIN; GA4 y cualquier otro tag se administran dentro de GTM respetando sus controles de consentimiento.
