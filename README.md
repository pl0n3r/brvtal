# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí, además del snapshot exacto de lo que cambió, un **panorama general actualizado de lo que sigue pendiente por hacer/desarrollar**.

## Qué se hizo

- #435 corrige hallazgos de seguridad reportados por SonarQube Cloud sobre `main` sin mezclar el trabajo con Memories.
- El Archive público deja de construir contenido derivado del API con `innerHTML`; tarjetas, filtros, textos y enlaces se crean con DOM APIs, `textContent` y propiedades de nodo.
- URLs de imágenes y tickets del Archive aceptan únicamente esquemas HTTP(S); esquemas no permitidos se descartan antes de asignarse al DOM.
- El enlace de conexiones del archivo se construye desde un path local conocido y un ID numérico, sin propagar el URL completo controlable del navegador al renderizado.
- `scripts/update-release-metadata.py` ancla la lectura/escritura a `config/version.php` resuelto desde la ubicación real del repositorio y rechaza escapes fuera de `config`.
- Se añade un contrato auto-descubierto que impide reintroducir `innerHTML` en Archive o volver a una ruta de metadata dependiente del working directory.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente actualizado.
- `js/archive.js` — elimina sinks DOM XSS y endurece URLs públicas.
- `scripts/update-release-metadata.py` — ancla y valida la ruta fija de metadata de release.
- `tests/sonar-security-contract.php` — regresión contractual para ambos hardenings.

## Validación

- Base exacta: `main` `8118d66c7263aaee57464ad7765c341521c3ffcc`.
- La suite de contratos PHP 8.5 y la validación de sintaxis JavaScript ya pasaron en el primer intento de CI del PR; el único fallo inicial fue este snapshot README desactualizado.
- El cambio debe pasar BRVTAL CI, SonarQube Cloud y revisión automatizada aplicable en el SHA exacto del PR antes del squash merge.
- CI verde significará **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Resolver cualquier finding válido de Sonar/CodeRabbit sobre #438, hacer squash merge y verificar BRVTAL CI del SHA exacto resultante de `main`.
- Volver a contrastar los issues Sonar de `main` después del merge y continuar con los hallazgos restantes de Security/Reliability por severidad.
- Mantener #434 (Memories) separado; no debe mergearse mientras su Quality Gate de Sonar siga rojo.

## Panorama general pendiente

Este panorama debe mantenerse actualizado en **cada deploy** y resumir trabajo relevante todavía abierto, aunque no forme parte del deploy actual.

- **Memories administrable:** #415 / PR #434 — galería curada desde DISCADMIN usando Media Library existente, con publicación, orden y viewer editorial. Falta cerrar Quality Gate y luego ejecutar la migración de producción por separado.
- **Sonar / seguridad:** continuar limpiando hallazgos reales de Security y Reliability de `main`, priorizando BLOCKER/HIGH y evitando suppressions especulativas.
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
