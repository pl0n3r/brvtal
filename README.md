# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Las páginas canónicas de Events dejan de mostrar compra cuando el Event ya es histórico o su fecha ya pasó.
- La política comercial queda centralizada en `brvtal_public_event_allows_ticketing()` dentro de la visibilidad pública compartida.
- `finished`, `archived` y `cancelled` nunca exponen el CTA `TICKETS`, aunque los datos comerciales sigan almacenados para administración e historial.
- Un Event todavía marcado como `published`/`upcoming` cuya fecha ya pasó tampoco mantiene CTAs de compra caducados.
- Los Events futuros en lifecycle activo conservan sus enlaces y tipos de entrada; `sold_out` sigue siendo un estado activo y puede comunicar entradas agotadas.
- Cuando ticketing ya no aplica, la hidratación de la página individual limpia `ticket_url` y `ticket_instructions` y evita consultar/renderizar `event_ticket_types`.
- El payload público de Archive ya saneaba tickets históricos mediante la partición canónica y no se modifica innecesariamente en este deploy.

## Archivos modificados en este deploy

- `config/public_visibility.php` — política canónica que decide si un Event todavía admite acciones de ticketing.
- `config/public_page.php` — suprime CTA, instrucciones y tipos de entrada en páginas canónicas históricas/pasadas.
- `tests/public-entity-pages-contract.php` — regresión determinista para Events futuros activos, sold out, cancelados, archivados y publicados con fecha pasada.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `2d291630a1172b2de8d762d15fc999993572776a`.
- Ese SHA exacto ya había pasado BRVTAL CI completo: fast, database, Chromium, WebKit, real-stack y validate; además PHP 8.5 Compatibility y Backup Recovery Rehearsal estaban verdes.
- La implementación no borra datos comerciales ni modifica esquema/DB: solo controla su entrega pública en función del lifecycle y la fecha.
- No se usa Work ni runner local en este flujo; la ejecución automatizada queda a cargo de los gates del PR.
- Pendiente de **BRVTAL CI / validate** y **PHP 8.5 Compatibility / php85** del PR.
- Tras el merge se verificará la matriz completa sobre el SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir en esta misma rama cualquier fallo detectado.
2. Con CI verde, hacer squash merge.
3. Verificar BRVTAL CI completo, PHP 8.5 y Backup Recovery sobre el SHA exacto resultante de `main`.
4. Cerrar #197 y dar por completada la tanda #240 → #210 → #196 → #197.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
