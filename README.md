# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Los fallbacks de API pública ya declarados por el frontend ahora existen realmente en el routing desplegable.
- `/api/public` y `/api/public/` delegan a la implementación canónica `api/public.php`.
- `/api/index.php?route=public` y `/api/index.php?action=public` también delegan a `api/public.php` antes de cualquier routing administrativo.
- Se mantiene una sola implementación/allowlist pública; las aliases no duplican lógica de datos ni autenticación.
- Si `/api/public.php` falla por una diferencia de URL/routing, los candidatos secundarios dejan de producir 404/401 falsos y pueden resolver el mismo payload público.

## Archivos modificados en este deploy

- `.htaccess` — añade aliases explícitas del API público hacia `api/public.php`.
- `tests/api-contract.php` — exige que todos los candidatos declarados por el frontend tengan routing público soportado y previo al auth administrativo.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `b74ac4018a1ede5518bd2342183df751cfee49dd`.
- Ese SHA exacto tenía BRVTAL CI completo, PHP 8.5 Compatibility, Backup Recovery Rehearsal y Production Performance en success.
- No hay cambios de esquema, migraciones ni mutaciones de datos de producción.
- `api/public.php` continúa siendo la única implementación pública de contenido/settings; las nuevas rutas son solo aliases de compatibilidad.
- Pendiente de **BRVTAL CI / validate** y **PHP 8.5 Compatibility / php85** del PR.
- Tras el merge se verificará la matriz completa sobre el SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir en esta misma rama cualquier fallo detectado.
2. Con CI verde, hacer squash merge.
3. Verificar BRVTAL CI completo, PHP 8.5 y Backup Recovery sobre el SHA exacto resultante de `main`.
4. Cerrar #253 y continuar con el siguiente issue público prioritario que siga vigente.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
