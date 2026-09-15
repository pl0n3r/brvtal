# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Los recovery codes usados durante el login 2FA ahora solo autentican a la request que logra consumir atómicamente el código; una segunda request concurrente ya no puede aceptar el mismo código.
- La desactivación de 2FA aplica un rate limit independiente del challenge de login para frenar intentos repetidos contra una sesión administrativa autenticada.
- Cuando DISABLE 2FA utiliza un recovery code, su consumo ocurre dentro de la misma transacción que desactiva 2FA y elimina los códigos restantes; un fallo posterior ya no deja el código consumido fuera de la operación.
- La desactivación también comprueba que el recovery code siga disponible al momento de consumirlo, cerrando la carrera entre verificaciones concurrentes.
- Se ampliaron los contratos TOTP para fijar estas garantías de throttling y atomicidad.
- No hay cambios de esquema, migraciones, permisos ni datos de producción.

## Archivos modificados en este deploy

- `config/totp_auth.php` — scope compartido de throttling 2FA y consumo atómico de recovery code durante login.
- `discadmin/totp-api.php` — throttling de DISABLE 2FA y consumo transaccional del recovery code.
- `tests/totp-enrollment-contract.php` — regresiones contractuales para throttling y single-use/atomicidad.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Base actual: `main` `ce64a17519f9dd8ab136978a86fcccd449ee7cbf`, después del merge de #339.
- El batch fue recontrastado y rebasado sobre ese SHA cuando `main` avanzó durante los gates; la rama queda un commit ahead y cero behind.
- El alcance objetivo cierra #166, #226 y #264.
- Los gates deben volver a quedar verdes sobre la base actual antes del squash merge.
- Tras el merge se verificará la matriz completa sobre el SHA exacto nuevo de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Revalidar los gates del PR sobre la base actual.
2. Con CI verde, mantener la rama en un solo commit, hacer squash merge y verificar la matriz completa sobre el nuevo SHA exacto de `main`.
3. Confirmar el cierre de #166, #226 y #264 y refrescar nuevamente todos los Issues abiertos.
4. Seleccionar el siguiente batch homogéneo de Quick Wins, manteniendo seguridad/integridad por encima de mejoras cosméticas cuando el esfuerzo sea similar.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
