# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- DISCADMIN ya no considera válida una sesión únicamente por conservar `admin_id` y estar dentro de los timeouts: cada comprobación autenticada revalida el estado actual del administrador en la tabla `admins`.
- Si el administrador fue desactivado (`is_active=0`) o eliminado, la sesión PHP se revoca y la autorización falla inmediatamente.
- Si la base de datos no permite comprobar el estado actual de la cuenta, el perímetro de autenticación falla cerrado: no autoriza la sesión con estado desconocido.
- La consulta de estado quedó aislada en un helper PDO pequeño y verificable, sin introducir una nueva tabla de sesiones ni cambiar cookies, CSRF, regeneración de session ID o los timeouts existentes.
- Se añadieron contrato PHP y regresión MariaDB para admin activo, desactivado, reactivado y eliminado.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo exacto de este deploy.
- `config/admin_auth.php` — revalida `admins.is_active`, revoca sesiones inactivas/eliminadas y falla cerrado si la revalidación no está disponible.
- `config/admin_session_revalidation.php` — helper PDO canónico para consultar el estado actual de una cuenta administrativa.
- `package.json` — incorpora la regresión de sesión al suite de integración canónico.
- `tests/admin-session-revalidation-contract.php` — protege la llamada de revalidación, el fail-closed y la destrucción de sesión para cuentas revocadas.
- `tests/integration/admin-session-revalidation.php` — valida contra MariaDB de test los estados activo → desactivado → reactivado → eliminado.

## Validación

- Base exacta: `main` `115a6659452e3d7f4cca95b6e767b8f88e42b720`, con `BRVTAL CI / validate` verde (run #562).
- Issue cubierto: `#167`.
- No hay migración, cambio de schema, nueva tabla de sesiones, restore, bulk delete ni mutación de datos de producción.
- La regresión MariaDB crea una base scratch con namespace `brvtal_test...`, prueba únicamente una cuenta CI y elimina esa base al terminar.
- Los controles existentes de sesión se conservan: idle ~7 días, absoluto ~30 días, cookie HttpOnly/SameSite=Strict, CSRF y regeneración del session ID.
- Pendiente en este snapshot: `BRVTAL CI / validate`, revisión CodeRabbit y análisis automático de SonarQube Cloud sobre el head final.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobar el deploy real y una sesión autenticada controlada.

## Qué sigue

1. Resolver en esta misma rama cualquier finding válido de BRVTAL CI, CodeRabbit o SonarQube Cloud.
2. Hacer squash merge solo con `BRVTAL CI / validate` verde y revisar los threads finales de CodeRabbit.
3. Verificar `BRVTAL CI / validate` del SHA exacto resultante en `main`.
4. Repriorizar el siguiente deploy por impacto entre integridad editorial, trazabilidad administrativa y fallos públicos todavía abiertos.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de validación: `docs/TESTING.md`.
- Issue abordado: `#167`.
