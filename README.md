# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí el snapshot exacto de lo que cambió y un panorama general actualizado de lo pendiente.

## Qué se hizo

- Continúa #451 con el HIGH `javascript:S7761` revalidado en la navegación de Content Core.
- `discadmin/content-core-nav.js` deja de consultar `data-health-open` mediante `hasAttribute()` y usa presencia de la clave `healthOpen` en `dataset`.
- Se preserva exactamente la semántica anterior: `data-health-open=""` sigue clasificando el botón como Content Health; no se usa truthiness del valor.
- La regresión Playwright existente ahora fuerza `data-health-open=""`, hace clic en OPEN y exige que el shell navegue a Events y abra exactamente el Event #7 sin feedback de error.
- Se añade un contrato fuente auto-descubierto que exige la comprobación por presencia y bloquea el regreso de `hasAttribute('data-health-open')`.
- No se modifican APIs, base de datos, permisos, rutas, estilos ni comportamiento visible.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente.
- `discadmin/content-core-nav.js` — modernización equivalente de la detección de `data-health-open`.
- `tests/content-core-health-presence-contract.php` — contrato fuente de semántica por presencia.
- `tests/e2e/discadmin-content-health-navigation.spec.mjs` — regresión ejecutable del atributo presente con valor vacío.

## Validación

- Base exacta: `main` `4b9daa710a7b917ff7887f10a73f64427a23d3f9`.
- Ese SHA exacto pasó BRVTAL CI #855 (`fast`, `database`, `real-stack`, `chromium`, `validate`) y #469 queda **VALIDATED IN CODE**.
- La rama fue reconstruida sobre ese `main` exacto después del merge de #469; no arrastra el README ni archivos del sitemap como diff propio.
- Este head debe pasar BRVTAL CI, SonarQube Cloud y CodeRabbit sobre su SHA exacto antes del squash merge.
- Después del merge se verificará BRVTAL CI sobre el SHA exacto resultante de `main`; CI verde significará **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Cerrar gates exactos de este bloque, corregir findings válidos, squash merge y verificar el nuevo `main`.
- Revalidar y tratar por separado los quick wins DOM todavía vigentes en `discadmin/media-library.js` (`dataset` y `Element.after()`) sin mezclar complejidad.
- Continuar #451 por riesgo con findings vigentes recontrastados contra el código actual.
- Mantener #434 (Memories) separado y no ejecutar automáticamente su migración de producción.

## Panorama general pendiente

- **Sitemap / SEO técnico:** #469 integrado y **VALIDATED IN CODE**; la validación externa de producción/Search Console sigue separada de CI.
- **Content Core navigation:** este deploy cierra el HIGH `S7761` de presencia de `data-health-open` con contrato y Playwright.
- **Media Library DOM:** quick wins `dataset`/`Element.after()` siguen revalidados; tratarlos en un PR pequeño separado.
- **Memories administrable:** #415 / PR #434 — cerrar su Quality Gate y tratar la migración de producción aparte.
- **Sonar / calidad:** #451 — continuar por riesgo y área evitando refactors masivos.
- **Archivo cultural:** #398 / #403 — relaciones explícitas Event ↔ Artist ↔ Set ↔ Release ↔ Memory.
- **Analytics / GA4:** #427 — completar mapeo `brvtal_*` en GTM/GA4.
- **SEO editorial:** #390, #391 y #272 — workspace SEO y structured data por entidad.
- **DISCADMIN:** #348, #351 y #365 — navegación, Theme Studio y consolidación de Dashboard.
- **Operación / historial:** #232, #207 y #388 — Activity, System Status y RESET LOG seguro.
- **Media / mobile:** #260 — inspector accesible inmediatamente tras seleccionar un asset en móvil.
- **Seguridad editorial / UX:** #257 — proteger cambios no guardados.
- **Backups:** #389 — scheduling seguro y copia opcional a Google Drive con autorización.
- **Bulk Actions:** #275 — superar el recorte local de 500 sin búsquedas parciales engañosas.
- **Idioma:** #212 — futura experiencia ES/EN manteniendo español canónico.
- **Performance / recovery:** optimizar solo ante regresiones o cuellos medidos.
