# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí, además del snapshot exacto de lo que cambió, un **panorama general actualizado de lo que sigue pendiente por hacer/desarrollar**.

## Qué se hizo

- Continúa #451 cerrando la deuda de revisión posterior a #461 sin cambiar lógica de producto.
- Se conserva el contrato de strings de los dos quick wins DOM y se añade evidencia ejecutable de sus comportamientos reales en Chromium.
- Record Lists ahora tiene una regresión que ejecuta `decorate()` nuevamente después de introducir un `data-label` stale y confirma que la metadata obsoleta se elimina antes de aplicar el esquema actual.
- Public Sets confirma en navegador que `.sets-library-controls` queda inmediatamente después de `.sets-intro`, cubriendo la semántica de `intro.after(controls)` y no solo el literal de código.
- Se documentan las funciones de test tocadas y el enhancer público de Sets para cerrar la advertencia de cobertura de docstrings detectada por CodeRabbit en #461.
- No se modifican APIs, base de datos, rutas, orden editorial, estilos ni comportamiento visible.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente actualizado.
- `js/public-sets-library.js` — docblock breve del enhancer; la lógica entregada por #461 permanece intacta.
- `tests/e2e/discadmin-mobile-record-lists.spec.mjs` — regresión ejecutable de limpieza de `data-label` stale tras redecoración.
- `tests/e2e/public-sets-library-phase-c.spec.mjs` — regresión ejecutable de la posición de controles después de `.sets-intro` y docblocks del harness.

## Validación

- Base exacta recontrastada: `main` `d0d65de8131889f20400df53587b24d7164d23b5`.
- Ese SHA exacto de `main` pasó BRVTAL CI #809 con conclusión `success`; #461 queda **VALIDATED IN CODE** en `main`.
- Production Performance #497 también terminó en `success` sobre ese mismo SHA; esto aporta evidencia operativa automatizada, no validación manual de producción.
- Este follow-up debe pasar BRVTAL CI, SonarQube Cloud y CodeRabbit sobre su SHA exacto antes del squash merge.
- Después del merge se verificará BRVTAL CI sobre el SHA exacto resultante de `main`; CI verde significará **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Completar los gates del head exacto de este follow-up, resolver findings válidos, hacer squash merge y verificar el CI del nuevo `main`.
- Preparar en un PR separado los HIGH DOM todavía vigentes en `discadmin/bulk-actions.js` y `discadmin/global-search.js`, usando `status.before(trigger)` como reemplazo equivalente de `top.insertBefore(trigger, status)`.
- Continuar #451 por riesgo con los siguientes findings de Reliability / accesibilidad y después Maintainability acotada.
- Mantener #434 (Memories) separado hasta resolver su propio Quality Gate y tratar su migración de producción por separado.

## Panorama general pendiente

Este panorama debe mantenerse actualizado en **cada deploy** y resumir trabajo relevante todavía abierto, aunque no forme parte del deploy actual.

- **DOM API quick wins:** #461 ya está integrado y VALIDATED IN CODE; este follow-up cierra su cobertura ejecutable y la advertencia documental de CodeRabbit.
- **DOM API siguiente bloque:** `discadmin/bulk-actions.js` y `discadmin/global-search.js` conservan dos HIGH equivalentes de inserción DOM ya revalidados y se tratarán en un PR separado.
- **Memories administrable:** #415 / PR #434 — galería curada desde DISCADMIN usando Media Library existente, con publicación, orden y viewer editorial; falta cerrar Quality Gate y ejecutar la migración de producción por separado.
- **Sonar / calidad:** #451 — los BLOCKER de seguridad/globals, `S2871`, `S7727`, los cuatro `S8786`, labels de Blog/Media/Releases, labels/semántica del wizard de Content Core y los primeros quick wins DOM están trabajados; continúa la cola por riesgo y área.
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
