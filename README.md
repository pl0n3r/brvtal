# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Se corrige la contradicción entre la URL compartible de Public Discovery y el estado que realmente queda visible cuando un filtro solicitado ya no existe.
- Un `archive_year` sin botón disponible vuelve a **ALL YEARS** y el parámetro inválido se elimina mediante `replaceState`.
- Un `network_id` inexistente conserva el comportamiento actual de CONNECTED: la capa solicitada selecciona su primer registro disponible y la URL se normaliza a ese registro visible.
- La canonicalización también se aplica al restaurar historial sin crear una entrada adicional de navegación.
- Si los controles o la API de CONNECTED todavía no están disponibles durante el arranque, el estado solicitado se conserva hasta que pueda resolverse, evitando perder deep links válidos por una carrera de inicialización.
- Se amplía el harness E2E para modelar correctamente que `BRVTALRelatedContent.select()` no cambia la selección ante un ID inexistente.

## Archivos modificados en este deploy

- `js/public-discovery-url-state.js` — reconcilia estado solicitado, controles visibles y URL canónica después de aplicar filtros/deep links.
- `tests/e2e/public-discovery-url-state.spec.mjs` — añade regresiones para año inexistente, ID CONNECTED inexistente y canonicalización durante `popstate` sin contaminar el historial.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Revisión estática del flujo completada sobre la rama enfocada creada desde el `main` verde `b82b43f877e7c22a1e80db5c9d48fa0937c6eac3`.
- Cobertura E2E dirigida añadida; su ejecución automatizada queda a cargo de los gates del PR porque este flujo no dispone de un runner local sin usar Work.
- Pendiente de **BRVTAL CI / validate** y **PHP 8.5 Compatibility / php85** del PR.
- Tras el merge se verificará la matriz completa sobre el SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Abrir el PR y ejecutar los gates path-aware de BRVTAL CI.
2. Corregir en la misma rama cualquier regresión detectada por Chromium/PHP 8.5.
3. Con CI verde, hacer squash merge y verificar la matriz completa sobre el SHA exacto de `main`.
4. Cerrar #292 con el merge y continuar con el siguiente hallazgo de Public Discovery del backlog.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
