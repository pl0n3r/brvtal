# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- La confirmación de enrollment 2FA ahora serializa la transición `disabled → enabled` bloqueando la fila del administrador con `SELECT ... FOR UPDATE` dentro de la transacción final.
- Después de adquirir el lock se vuelve a comprobar `totp_enabled`; una segunda confirmación concurrente ya no puede generar un nuevo juego de recovery codes ni invalidar los códigos devueltos por la primera respuesta exitosa.
- El `UPDATE` que habilita 2FA queda condicionado a `totp_enabled=0` y exige una única fila modificada antes de generar recovery codes.
- Se conserva intacta la atomicidad ya añadida para consumo de recovery codes en login y al desactivar 2FA.
- Se amplió el contrato TOTP para proteger el orden transacción → lock → recheck → generación de recovery codes.
- No hay cambios de esquema, migraciones ni acciones sobre 2FA o datos de producción.

## Archivos modificados en este deploy

- `discadmin/totp-api.php` — serializa la confirmación concurrente de enrollment y evita regenerar recovery codes después de que 2FA ya fue habilitado.
- `tests/totp-enrollment-contract.php` — añade regresiones de contrato para lock, recheck y transición condicional.
- `README.md` — snapshot operativo exacto de este deploy.

## Validación

- Base de trabajo: `main` `cc919680a8e2d2aeb71f6e25d6ed80f7758f670d`, con **BRVTAL CI / validate** exacto en verde antes de abrir este batch.
- El hallazgo #285 seguía presente en `main`: dos confirmaciones podían validar el mismo enrollment antes de que la primera transacción habilitara 2FA y la segunda no releía el estado después de adquirir el lock implícito del `UPDATE`.
- Pendiente: **BRVTAL CI / validate** del PR y, tras squash merge, validación del nuevo SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica que se haya habilitado, deshabilitado o reconfigurado 2FA real en producción.

## Qué sigue

1. Ejecutar el CI path-aware del PR y corregir cualquier regresión en esta misma rama.
2. Con `validate` verde, hacer squash merge y comprobar el SHA exacto nuevo de `main`.
3. Continuar con el siguiente hallazgo abierto de DISCADMIN sin duplicar trabajo.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- Issue cubierto: `#285`.
