# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- El rate limit del login por contraseña conserva el bloqueo activo antes de autenticar, pero ya no incrementa el contador por cada login válido.
- Solo una contraseña inválida registra un fallo; una contraseña correcta elimina el estado previo de esa combinación IP + email antes de continuar al shell o al challenge TOTP.
- HTTP 429 / `RATE_LIMITED` se muestra como un bloqueo temporal explícito, separado de credenciales inválidas y de fallos de servidor/red.
- La política de almacenamiento del contador se aisló en `config/password_rate_limit.php` para mantener el flujo de autenticación legible y testeable.
- La corrección de concurrencia interna de los archivos de rate limit queda deliberadamente fuera de este deploy y sigue separada del presente scope.
- No hay cambios de esquema, migraciones, permisos ni datos de producción.

## Archivos modificados en este deploy

- `api/index.php` — consulta el bloqueo antes de verificar, registra rate limit solo tras una contraseña inválida y resetea el estado tras contraseña válida.
- `config/password_rate_limit.php` — política compartida para leer, registrar fallos y resetear el rate limit de password.
- `discadmin/admin-reliability.js` — feedback diferenciado para rate limit, credenciales inválidas, validación incompleta y errores generales de login.
- `tests/e2e/discadmin-password-login-rate-limit.spec.mjs` — regresiones del orden de operaciones backend y del feedback visible del login.
- `README.md` — snapshot operativo exacto de este deploy.

## Validación

- Rama recompactada sobre `main` `9264215997c9695c3398f9ddb3154860c976dad0` después de los merges de #339 y #340.
- El batch fue recontrastado contra #340: sus cambios de 2FA están en `config/totp_auth.php` / `discadmin/totp-api.php` y no sustituyen la política de password de este deploy.
- En esa base, `fast` y PHP 8.5 Compatibility ya estaban verdes al rebase; la matriz exacta de `main` seguía ejecutándose.
- El cambio mantiene el mismo contrato externo de `RATE_LIMITED` / HTTP 429; no cambia rutas ni crea superficies administrativas paralelas.
- Pendiente de gates del PR y, tras el merge, matriz completa sobre el SHA exacto nuevo de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir cualquier fallo en esta misma rama.
2. Con CI verde, mantener un solo commit, hacer squash merge y verificar la matriz completa sobre el nuevo SHA exacto de `main`.
3. Confirmar el cierre de #154 y #281 y refrescar nuevamente todos los Issues abiertos.
4. Mantener #263 separado como hardening de concurrencia de los stores de rate limit.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
