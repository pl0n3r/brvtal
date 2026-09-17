# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí, además del snapshot exacto de lo que cambió, un **panorama general actualizado de lo que sigue pendiente por hacer/desarrollar**.

## Qué se hizo

- Continúa #451 con un PR pequeño de Reliability para `discadmin/media-library.js` y el finding Sonar `javascript:S2871`.
- El filtro mensual de Media Library deja de depender de `Array.sort()` sin comparador seguido de `reverse()` y usa un comparador explícito con `localeCompare`, conservando el mismo orden descendente visible.
- Se añade una prueba Playwright que monta la Media Library real con meses duplicados y desordenados y verifica la lista única `2026-10 → 2026-02 → 2025-12`.
- Se añade un contrato auto-descubierto que falla si reaparece `.sort().reverse()` o desaparece el comparador explícito.
- No se modifica autenticación, API, esquema de datos, uploads ni comportamiento de selección/edición de assets.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente actualizado.
- `discadmin/media-library.js` — comparador explícito locale-aware para el orden mensual descendente.
- `tests/media-library-sort-contract.php` — contrato de regresión específico para `S2871`.
- `tests/e2e/media-month-ordering.spec.mjs` — prueba ejecutable del orden y deduplicación del filtro mensual.

## Validación

- Base exacta: `main` `7252c5e129de081b894ec7aa0aea0d2ead145ce8`.
- Ese SHA exacto de `main` pasó BRVTAL CI con `fast`, Chromium, real-stack, WebKit TOTP y `validate` verdes; los gates no aplicables quedaron omitidos según el selector de scope.
- La rama debe pasar BRVTAL CI, SonarQube Cloud y revisión automatizada sobre el SHA exacto del PR antes del squash merge.
- CI verde significará **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Resolver cualquier finding válido de CI, Sonar o CodeRabbit sobre este PR; hacer squash merge solo con los gates aplicables verdes y verificar BRVTAL CI del SHA exacto resultante de `main`.
- Continuar #451 por riesgo con los siguientes findings Reliability: `js/related-content.js` `S7727` y regex `S8786`, cada uno en PR separado con cobertura proporcional.
- Mantener #434 (Memories) separado hasta resolver su propio Quality Gate y tratar su migración de producción por separado.

## Panorama general pendiente

Este panorama debe mantenerse actualizado en **cada deploy** y resumir trabajo relevante todavía abierto, aunque no forme parte del deploy actual.

- **Memories administrable:** #415 / PR #434 — galería curada desde DISCADMIN usando Media Library existente, con publicación, orden y viewer editorial; falta cerrar Quality Gate y ejecutar la migración de producción por separado.
- **Sonar / calidad:** #451 — los BLOCKER de seguridad/globals ya se trabajaron; quedan findings HIGH/MEDIUM de Reliability y deuda Maintainability, a resolver por riesgo y por área.
- **Archivo cultural:** #398 / #403 — profundizar relaciones explícitas Event ↔ Artist ↔ Set ↔ Release ↔ Memory sin inferencias falsas.
- **Analytics / GA4:** #427 — completar mapeo `brvtal_*` en GTM/GA4 y preparar lectura segura futura en Dashboard.
- **SEO:** #390, #391 y #272 — workspace SEO, alineación del bloque actual y structured data por entidad.
- **DISCADMIN:** #348, #351 y #365 — simplificación de navegación, Theme Studio y consolidación de Dashboard.
- **Operación / historial:** #232, #207 y #388 — Activity completo, System Status fiable y RESET LOG seguro.
- **Media / mobile:** #260 — inspector accesible inmediatamente tras seleccionar un asset en móvil.
- **Seguridad editorial / UX:** #257 — proteger cambios no guardados en editores legacy.
- **Backups:** #389 — scheduling seguro y copia opcional a Google Drive cuando existan autorización/credenciales.
- **Bulk Actions:** #275 — alcanzar registros más allá del recorte local de 500 sin presentar búsquedas parciales como exhaustivas.
- **Idioma:** #212 — futura experiencia ES/EN manteniendo español canónico.
- **Performance / recovery:** conservar evidencia y optimizar solo ante regresiones o cuellos de botella medidos.
