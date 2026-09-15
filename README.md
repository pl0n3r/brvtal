# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Media Picker y REGISTER EXTERNAL MEDIA heredan diálogo modal accesible, focus trap, Escape y restauración de foco.
- El challenge de login 2FA expone semántica modal, contiene el foco y permite cancelar con Escape devolviendo el foco al login.
- El cambio entre authenticator/recovery code es un botón real operable por teclado y actualiza el nombre accesible del campo.
- Los inputs de verificación de Security / 2FA tienen nombres accesibles explícitos.
- Theme Studio asocia programáticamente labels simples, nombra controles compuestos y hace IMPORT operable con Enter/Space.
- Playwright cubre los seis Quick Wins, incluido el flujo TOTP que también pasa por la regresión WebKit dirigida.
- Se preservan los latest-intent-wins ya presentes en Blog, Media Library, Bulk Actions y Admin Activity desde `main`.
- No hay cambios de API, esquema, migraciones, permisos ni datos de producción.

## Archivos modificados en este deploy

- `discadmin/admin-modal-accessibility.js` — extiende el boundary compartido a Media Picker y accesibilidad de Theme Studio.
- `discadmin/totp-login.js` — comportamiento modal completo y recovery toggle accesible en el challenge 2FA.
- `discadmin/totp-status.php` — nombres accesibles para los campos de confirmación/desactivación 2FA.
- `tests/e2e/discadmin-keyboard-modal-quick-wins.spec.mjs` — regresiones Media Picker, Theme Studio y Security para #164, #267, #273 y #282.
- `tests/e2e/discadmin-totp-login.spec.mjs` — regresiones de foco, Escape y recovery mode para #261 y #262.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama recompuesta sobre `main` `84ac1917907e07ede84f86dfc0d0aa9e5a076223`.
- Ese SHA exacto tiene BRVTAL CI completo —fast, database, Chromium, WebKit, real-stack y validate—, PHP 8.5 Compatibility y Backup Recovery Rehearsal en success.
- Los mismos seis fixes ya habían pasado los gates del PR sobre la base anterior; tras recomponer la rama se vuelven a ejecutar contra el `main` actual.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Mantener la rama en un único commit sobre el `main` actual y ejecutar nuevamente todos los gates del PR.
2. Corregir cualquier fallo en esta misma rama y volver a compactarla si fuera necesario.
3. Con CI verde, hacer squash merge y verificar la matriz completa sobre el nuevo SHA de `main`.
4. Continuar con otro batch de Quick Wins relacionados antes de abordar cambios estructurales.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
