# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. Se reemplaza en cada deploy. El contexto durable vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- Se cierra el recorrido bidireccional Archive ↔ CONNECTED para Events históricos usando únicamente el payload público existente.
- Un Event seleccionado en CONNECTED muestra `VIEW IN ARCHIVE ↗` solo si su ID existe realmente en `archive.events`; los Events activos no reciben ese CTA.
- El retorno limpia `network_type` / `network_id`, fija `archive_year` y `archive_q` con el registro histórico y aterriza en `#eventArchive`.
- No se infiere archivo por estado, no se duplican relaciones y no hay cambios de API, base de datos ni CSS.

## Archivos modificados en este deploy

- `js/related-content.js` — genera el retorno contextual a Archive únicamente para Events presentes en `archive.events`.
- `tests/e2e/public-related-content.spec.mjs` — cubre Event histórico → Archive, limpieza del estado CONNECTED y ausencia del CTA en Events activos.
- `README.md` — snapshot operativo de este deploy.

## Validación

- La rama debe pasar sintaxis JavaScript y la regresión dirigida de Related Content antes del merge.
- El PR debe pasar `BRVTAL CI / validate`, `PHP 8.5 Compatibility / php85` y el gate de README.
- Chromium debe demostrar que un Event histórico vuelve a su contexto exacto de Archive y que un Event activo no expone ese enlace.
- Después del merge se verificará el CI completo del SHA exacto de `main` y el deploy exacto en Hostinger.
- CI verde implica **VALIDATED IN CODE**; observar el SHA exacto en Hostinger implica **DEPLOYED**.

## Qué sigue

1. Continuar discovery público solo con relaciones estructuradas reales y recorridos que hoy terminen en callejones sin salida.
2. Mantener #122–#125 abiertos hasta ejecutar sus workflows autenticados y obtener evidencia real de producción.
3. Volver a DISCADMIN solo ante fricción reproducible, preservando ONE SHELL / ONE SIDEBAR / ONE SESSION / ONE CENTRAL WORKSPACE.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.