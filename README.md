# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Se cierra la carrera TOCTOU del borrado de Media mediante coordinación transaccional en MariaDB.
- Las escrituras que pueden crear referencias a Media comparten un mutex InnoDB con el DELETE de Media.
- DELETE vuelve a comprobar Events, Artists, Sets, Ticket Types, Releases, Blog, Pages y Settings mientras mantiene ese mutex.
- Al completar un DELETE, el `file_path` queda tombstoned para impedir que una escritura posterior cree una referencia a un asset ya eliminado.
- La solución conserva el modelo actual basado en rutas reutilizables; no introduce un segundo Media model ni mini-servicios.
- Se añade una integración MariaDB que reproduce las dos intercalaciones de concurrencia relevantes.

## Archivos modificados en este deploy

- `database/migration_zz_media_reference_guard_01.sql` — mutex, tombstones y triggers de integridad para referencias/borrado de Media.
- `tests/integration/media-reference-atomicity.php` — reproduce writer-first y delete-first contra MariaDB real.
- `package.json` — incorpora la nueva integración al conjunto `test:integration`.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Pendiente de BRVTAL CI y PHP 8.5 Compatibility del PR.
- El cambio de aplicación es SQL aditivo e idempotente; no borra contenido existente.
- **La migración no se ejecuta automáticamente en producción al hacer merge.** Source deploy y migración de base de datos siguen siendo operaciones separadas.
- No se ejecutará SQL de producción desde este flujo sin aprobación explícita.
- Tras el merge se verificará la matriz completa sobre el SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION** ni **MIGRATION APPLIED IN PRODUCTION**.

## Qué sigue

1. Cerrar #294 después de CI verde y verificar el SHA exacto de `main`.
2. Aplicar la migración en producción solo mediante el proceso explícito de migraciones y con autorización del usuario.
3. Tras aplicar la migración, validar en producción un DELETE seguro de un asset de prueba no referenciado y el bloqueo de uno referenciado.
4. Continuar con el siguiente bloque de estabilización del backlog.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- Estado/mecanismo de migraciones: `config/migrations.php`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
