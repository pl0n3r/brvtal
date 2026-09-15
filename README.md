# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Las rutas canónicas de entidades inexistentes o no públicas ya no continúan hacia el renderer completo de Home.
- Un deep link inválido conserva HTTP 404 y `X-Robots-Tag: noindex, follow`, pero ahora devuelve una vista dedicada `RESOURCE NOT FOUND`.
- La vista 404 ofrece recuperación clara hacia Home y no incluye el contenido `NEXT EXPERIENCE` ni el resto de la portada.
- El SEO del 404 usa título/descripcion propios y un canonical que corresponde a la ruta solicitada, en lugar de describir `/`.
- El body 404 incluye además `<meta name="robots" content="noindex, follow">` como defensa explícita en HTML.

## Archivos modificados en este deploy

- `index.php` — termina rutas canónicas inválidas antes de cargar/renderizar Home.
- `config/public_seo.php` — añade documento SEO específico para recursos no encontrados.
- `config/public_not_found.php` — renderer público dedicado de recuperación 404.
- `tests/public-seo-delivery-contract.php` — cubre salida temprana, noindex, canonical solicitado y ausencia del Home en el 404.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `2b49d9e8d7bf9c37b92e9b46bade19aca065315c`.
- Ese SHA exacto tenía BRVTAL CI completo, PHP 8.5 Compatibility y Backup Recovery Rehearsal en success.
- No hay cambios de esquema, migraciones ni mutaciones de datos de producción.
- La corrección reutiliza `public-entity.css` y el sistema SEO público existente; no crea una segunda experiencia de navegación.
- Pendiente de **BRVTAL CI / validate** y **PHP 8.5 Compatibility / php85** del PR.
- Tras el merge se verificará la matriz completa sobre el SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir en esta misma rama cualquier fallo detectado.
2. Con CI verde, hacer squash merge.
3. Verificar BRVTAL CI completo, PHP 8.5 y Backup Recovery sobre el SHA exacto resultante de `main`.
4. Cerrar #277 y continuar con el siguiente issue público prioritario que siga vigente.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
