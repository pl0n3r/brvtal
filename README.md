# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí, además del snapshot exacto de lo que cambió, un **panorama general actualizado de lo que sigue pendiente por hacer/desarrollar**.

## Qué se hizo

- Continúa #451 con dos HIGH equivalentes de Maintainability ya revalidados en DISCADMIN.
- `discadmin/bulk-actions.js` inserta el trigger antes del estado con `status.before(trigger)` en lugar de `top.insertBefore(trigger,status)` (`javascript:S7768`).
- `discadmin/global-search.js` aplica el mismo reemplazo DOM, preservando posición, fallback y comportamiento visible.
- Ambos `ensureTrigger()` reciben documentación breve para mantener la cobertura de docstrings del cambio.
- Se añade un contrato dirigido que exige `Element.before()` en ambos módulos y evita la regresión al patrón legacy.
- No se modifican APIs, base de datos, rutas, permisos, búsquedas, acciones bulk ni comportamiento visible.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente actualizado.
- `discadmin/bulk-actions.js` — modernización de inserción del trigger bulk y docblock de la función tocada.
- `discadmin/global-search.js` — modernización de inserción del trigger de búsqueda global y docblock de la función tocada.
- `tests/sonar-dom-before-contract.php` — contrato dirigido de regresión para los dos HIGH de inserción DOM.

## Validación

- Base exacta recontrastada: `main` `d0d65de8131889f20400df53587b24d7164d23b5`.
- Ese SHA exacto de `main` pasó BRVTAL CI #809 con conclusión `success`; #461 queda **VALIDATED IN CODE** en `main`.
- Este head debe pasar BRVTAL CI, SonarQube Cloud y CodeRabbit sobre su SHA exacto antes del squash merge.
- Tras el merge, el SHA exacto resultante de `main` debe volver a pasar BRVTAL CI antes de considerar este bloque **VALIDATED IN CODE**.
- CI verde no equivale a validación en producción.

## Qué sigue

- Completar los gates del PR de `bulk-actions/global-search`, resolver cualquier finding válido, hacer squash merge y verificar CI del SHA exacto resultante de `main`.
- Continuar #451 por riesgo con el siguiente bloque de Reliability / accesibilidad o Maintainability acotada revalidado contra el `main` resultante.
- Mantener #434 (Memories) separado hasta resolver su propio Quality Gate y tratar su migración de producción por separado.

## Panorama general pendiente

Este panorama debe mantenerse actualizado en **cada deploy** y resumir trabajo relevante todavía abierto, aunque no forme parte del deploy actual.

- **DOM API maintainability:** los quick wins de #461 (`dataset` y `Element.after`) ya están **VALIDATED IN CODE**; este deploy elimina los dos HIGH equivalentes de `insertBefore` en `bulk-actions.js` y `global-search.js`.
- **Memories administrable:** #415 / PR #434 — galería curada desde DISCADMIN usando Media Library existente, con publicación, orden y viewer editorial; falta cerrar Quality Gate y ejecutar la migración de producción por separado.
- **Sonar / calidad:** #451 — los BLOCKER de seguridad/globals, `S2871`, `S7727`, los cuatro `S8786`, labels de Blog/Media/Releases, labels/semántica de Content Core y los primeros quick wins DOM están trabajados; continúa la cola por riesgo y área.
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
