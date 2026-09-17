# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

## Qué se hizo

- Google Tag Manager queda como **única capa pública de tags** de BRVTAL.
- `Settings → Analytics & Privacy` expone solo un `Google Tag Manager Container ID`; el campo GA4 directo se retira del flujo principal.
- El runtime público ya no carga `gtag.js` ni configura GA4 directamente.
- GTM se solicita únicamente después de consentimiento explícito del visitante; no se instala el bloque `noscript` para no romper el modelo consent-first.
- Al guardar GTM se limpian las claves legacy de GA4/aliases conocidas sin tocar otras claves hermanas del JSON de Analytics.
- Se conserva compatibilidad de lectura con claves GTM legacy de Theme Studio mientras se migra al campo canónico `analytics.gtm_id`.
- Se amplían contratos PHP y Playwright para validar formato GTM, ausencia de GA4 directo y carga/revocación bajo consentimiento.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy.
- `config/public_analytics.php` — valida/resuelve el Container ID de GTM y genera el bootstrap público consent-gated.
- `discadmin/settings-v2.js` — convierte Analytics en configuración GTM-only y retira GA4 directo del flujo normal.
- `index.php` — resuelve Analytics públicas mediante el Container ID canónico de GTM.
- `js/public-analytics.js` — carga `gtm.js` solo tras consentimiento y conserva revocación mediante recarga limpia.
- `tests/public-analytics-contract.php` — cubre validación y entrega GTM sin `gtag.js` directo.
- `tests/settings-control-plane-contract.php` — cubre el control plane GTM-only y la retirada del campo GA4.
- `tests/e2e/public-analytics.spec.mjs` — cubre rechazo, aceptación y revocación sin requests GTM prematuros.

## Validación

- Base: `main` `d95a2b6a6696ab59064126bc3f8f23ee48360473`.
- BRVTAL CI y revisión automatizada deben quedar verdes antes del squash merge.
- CI verde significa **VALIDATED IN CODE**, no validación de producción.
- La configuración persistida de producción sigue siendo independiente del deploy de código: el Container ID debe existir en `Settings → Analytics & Privacy` para que GTM se emita públicamente.

## Qué sigue

- Cerrar findings válidos de review, obtener CI verde, hacer squash merge y verificar el CI del SHA exacto de `main`.
- Tras el deploy, dejar `GTM-W23PHGJG` guardado como Container ID canónico en DISCADMIN; GA4 y cualquier otro tag se administran dentro de GTM.
