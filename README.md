# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. Se reemplaza en cada deploy. El contexto durable vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- Se implementa un smoke autenticado de producción para cerrar la brecha de validación de #123, #124 y #125 sin crear, editar ni eliminar contenido real.
- El workflow es exclusivamente manual, solo corre desde `main`, comprueba el SHA exacto desplegado en Hostinger y usa credenciales aisladas en el environment `production-smoke`.
- Después del login/2FA, el navegador queda protegido por una guardia read-only: solo GET/HEAD/OPTIONS llegan a producción; el POST automático de reparación de permisos de Media se responde localmente y cualquier otra mutación se bloquea y falla la ejecución.
- La evidencia JSON comprueba reapertura de fecha de Event (#123), relaciones publicadas en New Set (#124) y tres aperturas consecutivas del Hero Slider (#125).
- Se añade un contrato estático para impedir que el smoke se convierta accidentalmente en automático, use credenciales embebidas o pierda la barrera no destructiva.

## Archivos modificados en este deploy

- `.github/workflows/production-authenticated-smoke.yml` — workflow manual autenticado, restringido a `main`, con evidencia descargable.
- `tests/e2e/production-authenticated-smoke.mjs` — probe Chromium read-only para #123, #124 y #125 con soporte TOTP.
- `tests/production-smoke-contract.php` — contrato de seguridad/operación del smoke.
- `package.json` — incorpora el nuevo contrato a `npm run test:contracts`.
- `docs/TESTING.md` — procedimiento, secretos requeridos, límites de seguridad y criterio de VALIDATED IN PRODUCTION.
- `README.md` — snapshot operativo de este deploy.

## Validación

- El PR debe pasar `BRVTAL CI / validate` y `PHP 8.5 Compatibility / php85` antes del merge.
- El nuevo contrato corre dentro de la suite normal y valida que el workflow siga siendo manual-only, canónico y protegido contra mutaciones de contenido.
- Este deploy **no equivale todavía a VALIDATED IN PRODUCTION**: el smoke autenticado debe ejecutarse manualmente desde `main` después del merge, con los secretos del environment `production-smoke` configurados.
- Producción canónica: `https://www.brvtal.com.co`.

## Qué sigue

1. Tras merge + CI verde de `main`, configurar/verificar los secretos `BRVTAL_PROD_ADMIN_EMAIL`, `BRVTAL_PROD_ADMIN_PASSWORD` y, si aplica, `BRVTAL_PROD_TOTP_SECRET` en el environment `production-smoke`.
2. Ejecutar manualmente `Authenticated Production Smoke` sobre el SHA exacto de `main` y revisar `production-authenticated-smoke.json`.
3. Si los tres checks pasan, registrar la evidencia en #123, #124 y #125 y cerrarlos como **VALIDATED IN PRODUCTION**.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Especificaciones profundas: `docs/`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
