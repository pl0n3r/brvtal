# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí, además del snapshot exacto de lo que cambió, un **panorama general actualizado de lo que sigue pendiente por hacer/desarrollar**.

## Qué se hizo

- #435 corrige los dos BLOCKER de seguridad reportados por SonarQube Cloud sobre `main` sin mezclar el trabajo con Memories ni con la limpieza general de deuda Sonar.
- El Archive público deja de construir contenido derivado del API con `innerHTML`; tarjetas, filtros, textos y enlaces se crean con DOM APIs, `textContent` y propiedades de nodo.
- URLs de imágenes y tickets del Archive aceptan únicamente esquemas HTTP(S); esquemas no permitidos se descartan antes de asignarse al DOM.
- El enlace de conexiones del archivo se construye desde un path local conocido y un ID numérico, sin propagar el URL completo controlable del navegador al renderizado.
- `scripts/update-release-metadata.py` ancla la lectura/escritura a `config/version.php` resuelto desde la ubicación real del repositorio y rechaza escapes fuera de `config`.
- Los callbacks usados por `Array.map(...)` en Archive se adaptan explícitamente para evitar las dos advertencias de Reliability introducidas por el primer hardening.
- Se añade cobertura Playwright con payloads hostiles para demostrar que strings HTML se renderizan como texto y que `javascript:` no genera imágenes ni enlaces ejecutables.
- El contrato Sonar auto-descubierto impide reintroducir `innerHTML`, callbacks directos problemáticos o una ruta de metadata dependiente del working directory.
- El resto del baseline Sonar queda documentado y priorizado en #451; el finding de `discadmin/media-library.js` se atiende allí en un PR separado para mantener #438 enfocado.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente actualizado.
- `js/archive.js` — elimina sinks DOM XSS, endurece URLs y adapta callbacks de `map`.
- `scripts/update-release-metadata.py` — ancla y valida la ruta fija de metadata de release.
- `tests/e2e/public-archive.spec.mjs` — prueba ejecutable contra HTML/XSS y esquemas URL hostiles.
- `tests/sonar-security-contract.php` — regresión contractual para los hardenings y callbacks del Archive.

## Validación

- Base exacta: `main` `8118d66c7263aaee57464ad7765c341521c3ffcc`.
- En el head anterior de #438, BRVTAL CI terminó verde y SonarQube Cloud falló únicamente el Quality Gate por Reliability C en código nuevo.
- El head actual incluye la corrección de esas dos advertencias y la cobertura Playwright solicitada por CodeRabbit; requiere una nueva pasada de BRVTAL CI, SonarQube Cloud y revisión automatizada sobre el SHA exacto antes del squash merge.
- CI verde significará **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Confirmar BRVTAL CI + Sonar + review sobre el head exacto de #438; resolver cualquier finding válido restante, hacer squash merge y verificar BRVTAL CI del SHA exacto resultante de `main`.
- Continuar #451 por riesgo: primero BLOCKER/HIGH reales, luego Reliability con efecto de runtime/accesibilidad y después Maintainability en PRs pequeños por área.
- Mantener #434 (Memories) separado; no debe mergearse mientras su Quality Gate de Sonar siga rojo.

## Panorama general pendiente

Este panorama debe mantenerse actualizado en **cada deploy** y resumir trabajo relevante todavía abierto, aunque no forme parte del deploy actual.

- **Memories administrable:** #415 / PR #434 — galería curada desde DISCADMIN usando Media Library existente, con publicación, orden y viewer editorial. Falta cerrar Quality Gate y luego ejecutar la migración de producción por separado.
- **Sonar / calidad:** #451 — baseline de 2.141 findings agrupado por riesgo; después de #438 siguen cuatro BLOCKER de declaraciones JS, Reliability HIGH/MEDIUM y deuda Maintainability que debe resolverse por áreas sin refactors masivos.
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
