# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- El API público alinea ahora el envelope JSON con el status HTTP: respuestas exitosas conservan `ok:true`, mientras errores 4xx/5xx producen `ok:false`.
- `METHOD_NOT_ALLOWED` devuelve `{"ok":false,"error":"METHOD_NOT_ALLOWED"}` en vez de quedar envuelto como un éxito.
- Los errores públicos generados por este helper usan `Cache-Control: no-store`; el payload público exitoso conserva el caching existente.
- Se añadió un helper puro para mantener una sola política de envelope y probarla sin ejecutar queries del endpoint público.
- El contrato cubre éxito 200, 405 explícito y fallback genérico para errores sin código.
- No hay cambios de esquema, migraciones ni mutaciones de datos de producción.

## Archivos modificados en este deploy

- `api/public-response.php` — política canónica del envelope JSON público según status HTTP.
- `api/public.php` — consume el helper y evita cachear respuestas de error como contenido público válido.
- `tests/api-contract.php` — valida semántica de éxito/error y conexión del endpoint con el helper.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `21be3772d373a444ce482f2e9ca478db05c9665f`.
- Ese SHA exacto tenía BRVTAL CI completo, PHP 8.5 Compatibility y Backup Recovery Rehearsal en success.
- No hay cambios de schema ni migraciones; la corrección se limita al contrato de respuesta del API público.
- Pendiente de **BRVTAL CI / validate**, **PHP 8.5 Compatibility / php85**, **README Deploy Snapshot · PR** y **Backup Recovery Rehearsal** del PR.
- Tras el merge se verificará la matriz completa sobre el SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir en esta misma rama cualquier fallo detectado.
2. Con CI verde, hacer squash merge.
3. Verificar BRVTAL CI completo, PHP 8.5 y Backup Recovery sobre el SHA exacto resultante de `main`.
4. Confirmar #208 cerrado y continuar con el siguiente issue público prioritario vigente.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
