# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí, además del snapshot exacto de lo que cambió, un **panorama general actualizado de lo que sigue pendiente por hacer/desarrollar**.

## Qué se hizo

- Continúa #451 con dos HIGH de Maintainability ya revalidados, mediante reemplazos DOM acotados y equivalentes en comportamiento.
- `discadmin/admin-record-lists.js` elimina `data-label` mediante `delete cell.dataset.label` en lugar de `removeAttribute('data-label')` (`javascript:S7761`).
- `js/public-sets-library.js` inserta los controles con `intro.after(controls)` en lugar de `insertAdjacentElement('afterend', controls)` (`javascript:S7768`).
- Se añade documentación breve a la función tocada de record lists y al helper del contrato para mantener la cobertura de docstrings del cambio.
- El contrato dirigido evita que regresen ambos patrones legacy y confirma las APIs modernas esperadas.
- No se modifican APIs, base de datos, rutas, orden editorial, estilos ni comportamiento visible.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente actualizado.
- `discadmin/admin-record-lists.js` — modernización de la eliminación de `data-label` y docblock de la función tocada.
- `js/public-sets-library.js` — modernización de la inserción de controles de la librería pública de sets.
- `tests/sonar-dom-api-quick-wins-contract.php` — contrato dirigido de regresión para los dos quick wins DOM.

## Validación

- Base exacta recontrastada: `main` `7de90b80df954a963dc3cc9e1610fe069dd017e3`.
- Ese SHA exacto de `main` pasó BRVTAL CI #804 con `fast`, `chromium`, `real-stack` y `validate` en `success`; #460 queda **VALIDATED IN CODE** en `main`.
- El PR previo #459 fue cerrado sin merge porque conservaba una base antigua; #461 es el reemplazo limpio reconstruido desde el `main` exacto validado.
- El head exacto de #461 debe pasar BRVTAL CI, SonarQube Cloud y CodeRabbit antes del squash merge; no se reutilizan gates de #459 ni de SHA previos.
- CI verde significará **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Completar los gates del head exacto de #461, resolver cualquier finding válido, hacer squash merge solo con todos los gates aplicables verdes y verificar BRVTAL CI del SHA exacto resultante de `main`.
- Preparar en un PR separado los HIGH DOM todavía vigentes en `discadmin/bulk-actions.js` y `discadmin/global-search.js`.
- Continuar #451 por riesgo con los siguientes findings de Reliability / accesibilidad y después Maintainability acotada.
- Mantener #434 (Memories) separado hasta resolver su propio Quality Gate y tratar su migración de producción por separado.

## Panorama general pendiente

Este panorama debe mantenerse actualizado en **cada deploy** y resumir trabajo relevante todavía abierto, aunque no forme parte del deploy actual.

- **DOM API maintainability:** PR #461 — dos HIGH quick wins (`dataset` y `Element.after`) recontrastados contra `main` `7de90b8`; falta completar gates del head exacto, merge serializado y CI exacto de `main`.
- **DOM API siguiente bloque:** `discadmin/bulk-actions.js` y `discadmin/global-search.js` conservan dos HIGH equivalentes de inserción DOM ya revalidados y se tratarán en un PR separado.
- **Memories administrable:** #415 / PR #434 — galería curada desde DISCADMIN usando Media Library existente, con publicación, orden y viewer editorial; falta cerrar Quality Gate y ejecutar la migración de producción por separado.
- **Sonar / calidad:** #451 — los BLOCKER de seguridad/globals, `S2871`, `S7727`, los cuatro `S8786`, labels de Blog/Media/Releases y labels/semántica del wizard de Content Core están trabajados; continúa la cola por riesgo y área.
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
