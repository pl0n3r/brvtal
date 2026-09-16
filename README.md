# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- DISCADMIN Dashboard pasa de un muro de shortcuts/métricas decorativas a un overview editorial y operativo centrado en **qué necesita atención ahora**.
- Se añadió una fuente dedicada y read-only para Dashboard que usa la política canónica de lifecycle público de Events, expone el próximo evento, backlog de drafts, contenido público activo y Media real, sin depender de la tabla legacy de Analytics.
- Content Health separa ahora **public readiness** de **draft completeness**: un draft legítimamente incompleto ya no reduce el score de salud pública.
- Dashboard V2 integra Next Event, warnings de publicación, draft backlog, Content Health público, Admin Activity reciente, health real API/DB/PHP, storage administrado y build desplegado.
- Los fallos de fuentes independientes se muestran como `UNAVAILABLE`/`DEGRADED`; no se convierten silenciosamente en ceros.
- La versión de PHP se toma del health runtime y deja de depender de una etiqueta hard-coded.
- Quick Create queda como acción secundaria y `MEDIA LIBRARY` navega al workflow canónico, evitando el alta legacy de Media desde Dashboard.
- Se elimina de la experiencia V2 la métrica `ANALYTICS / 30D` basada en `analytics_events`, ya que esa tabla no representa la telemetría pública vigente.
- Se añadió contrato PHP específico para el wiring, semántica de fuentes y comportamiento responsive de Dashboard V2.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo exacto de este deploy.
- `api/content-health.php` — separa salud pública de completitud de drafts.
- `api/dashboard-overview.php` — fuente read-only de señales editoriales/operativas del Dashboard.
- `discadmin/content-health.js` — presenta Public Score y Draft Backlog como señales distintas.
- `discadmin/dashboard-v2.css` — layout responsive y jerarquía visual de Dashboard V2.
- `discadmin/dashboard-v2.js` — composición del overview, fuentes independientes, Next Event, health, storage, activity y quick actions seguros.
- `discadmin/index.php` — carga versionada de Dashboard V2 dentro del shell canónico.
- `tests/dashboard-v2-contract.php` — contrato de fuentes, lifecycle, resiliencia, navegación y responsive.

## Validación

- Base de trabajo: `main` `0a0f94d45f8a9599218e961aa6bc33b9245273c9`.
- Ese SHA tenía BRVTAL CI exacto en verde y Production Performance exitoso, incluido `Wait for exact Hostinger deploy`.
- No había PRs abiertos al iniciar este bloque.
- Se reutilizan hallazgos existentes de Dashboard y Content Health; no se abrió trabajo duplicado.
- La implementación mantiene ONE SHELL / ONE SIDEBAR / ONE SESSION / ONE CENTRAL WORKSPACE.
- Pendiente: CI path-aware del PR y, después del squash merge, CI + Production Performance del SHA exacto de `main`.
- CI verde significará **VALIDATED IN CODE**. La composición visual final y los datos reales del Dashboard requieren validación en producción tras deploy.

## Qué sigue

1. Ejecutar CI del PR, corregir cualquier regresión y hacer squash merge si `validate` queda verde.
2. Confirmar deploy exacto en Hostinger para el nuevo SHA de `main`.
3. Retomar inmediatamente la auditoría diagnóstica sobre el nuevo `main`, priorizando bugs abiertos de integridad, navegación, publicación y runtime público.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Dashboard / producto: `#350`.
- Bugs directamente abordados por este bloque: `#359`, `#248`, `#233`, `#187`, `#188`, `#235`.
