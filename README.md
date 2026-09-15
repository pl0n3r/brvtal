# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Las páginas canónicas públicas ya no convierten silenciosamente una excepción SQL en una colección vacía indistinguible de “sin datos”.
- Las lecturas esenciales de cada entidad —Event, Artist, Release, Blog, Set y Page— propagan el fallo hasta el boundary HTTP y responden `503`, `noindex`, `Retry-After` y `Cache-Control: no-store` con una experiencia explícita de indisponibilidad temporal.
- Las relaciones opcionales pueden seguir degradando parcialmente la ficha, pero el fallo queda registrado, la página muestra un aviso de datos incompletos, envía `X-BRVTAL-Data-State: degraded`, queda `noindex` y no se cachea durante la incidencia.
- Una relación legítimamente vacía sigue representándose normalmente como `[]`; solo las excepciones de query activan el estado degradado.
- No se cambian reglas editoriales, lifecycle, schema ni datos de producción.

## Archivos modificados en este deploy

- `config/public_page.php` — separa lecturas esenciales de relaciones opcionales, conserva la semántica de error y marca degradación parcial.
- `config/public_unavailable.php` — añade la respuesta pública segura para fallos esenciales de datos canónicos.
- `index.php` — entrega 503/noindex/no-store para fallos esenciales y headers de degradación/no-cache para fallos parciales.
- `tests/public-entity-pages-contract.php` — fija el contrato de observabilidad, 503, degradación parcial y noindex/no-cache.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `84d69d26e9cf9d429cd3bf1b60679bdac5b713f8`.
- Ese SHA exacto tenía BRVTAL CI completo, PHP 8.5 Compatibility y Backup Recovery Rehearsal en success.
- No hay cambios de esquema, migraciones ni mutaciones de datos de producción.
- Pendiente de **BRVTAL CI / validate**, **PHP 8.5 Compatibility / php85**, **README Deploy Snapshot · PR** y **Backup Recovery Rehearsal** del PR.
- Tras el merge se verificará la matriz completa sobre el SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir en esta misma rama cualquier fallo detectado.
2. Con CI verde, hacer squash merge.
3. Verificar BRVTAL CI completo, PHP 8.5 y Backup Recovery sobre el SHA exacto resultante de `main`.
4. Confirmar #276 cerrado y continuar con el siguiente issue público prioritario vigente.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
