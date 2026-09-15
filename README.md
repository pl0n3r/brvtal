# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- La regla de publicación previa de Events históricos queda centralizada en `brvtal_public_event_is_visible()`.
- Un Event futuro o sin fecha en `finished`, `archived` o `cancelled` ya no es público si carece de `published_at`.
- Los Events históricos pasados siguen siendo descubribles aunque registros legacy no tengan `published_at`.
- Archive reutiliza el mismo predicado en vez de mantener una segunda implementación.
- Las rutas canónicas `/events/{slug}` aplican la misma regla antes de renderizar metadata/página.
- `sitemap.xml` filtra Events con el mismo predicado y no indexa Events que la política pública considera privados.
- Relaciones de Artist, Set y Blog hacia Events también pasan por la política canónica para evitar fugas indirectas.

## Archivos modificados en este deploy

- `config/public_visibility.php` — predicado canónico de visibilidad de Event y parser de lifecycle dates.
- `api/public-archive.php` — reutiliza el predicado canónico durante la partición Active/Archive.
- `config/public_seo.php` — valida publication proof en rutas canónicas de Events.
- `sitemap.php` — valida publication proof antes de publicar URLs de Events.
- `config/public_page.php` — filtra relaciones públicas hacia Events con la misma política.
- `tests/public-archive-contract.php` — casos directos de lifecycle/publication proof.
- `tests/public-seo-delivery-contract.php` — contrato de consistencia entre ruta, sitemap y relaciones.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `95d3c878c6af039f944883ad625404978a93961a`, cuyo ciclo post-merge exacto pasó fast, database, Chromium, WebKit, real-stack y validate.
- Los casos cubren: active visible, historical futuro/indatado sin publicación oculto, historical futuro publicado visible, historical pasado legacy visible y draft siempre privado.
- No se usa Work ni existe runner local en este flujo; la ejecución automatizada queda a cargo de los gates del PR.
- Pendiente de **BRVTAL CI / validate** y **PHP 8.5 Compatibility / php85** del PR.
- Tras el merge se verificará la matriz completa sobre el SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Abrir el PR y ejecutar los gates path-aware.
2. Corregir en la misma rama cualquier fallo detectado.
3. Con CI verde, hacer squash merge y verificar la matriz completa sobre el SHA exacto de `main`.
4. Cerrar #196 y continuar serialmente con #197.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
