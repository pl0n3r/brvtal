# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí, además del snapshot exacto de lo que cambió, un **panorama general actualizado de lo que sigue pendiente por hacer/desarrollar**.

## Qué se hizo

- Continúa #451 con un bloque acotado de accesibilidad en Content Core, sin cambiar el comportamiento de los editores.
- Las búsquedas de Events y Artists reciben asociaciones `<label for>` reales y reutilizan la utilidad compartida `.admin-sr-only` entregada por #457.
- Los 14 campos visibles del editor de Events asocian explícitamente cada `<label>` con su `input`, `textarea` o `select` correspondiente.
- Los cinco pasos visuales del wizard dejan de anunciarse falsamente como botones: el runtime solo los usa como indicadores de estado y no les asigna interacción directa.
- El contrato de regresión parsea el fragmento con DOMDocument/DOMXPath y verifica estructuralmente las asociaciones label/control, el tipo de control, los labels ocultos de búsqueda y que los indicadores del wizard no entren al tab order ni reclamen semántica interactiva.
- No se modifican APIs, base de datos, navegación, lifecycle, tickets ni lógica de negocio.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente actualizado.
- `discadmin/content-core.php` — asociaciones accesibles para búsquedas/campos visibles y semántica correcta de los indicadores del wizard.
- `tests/content-core-form-accessibility-contract.php` — contrato estructural de regresión para Content Core.

## Validación

- Base exacta recontrastada: `main` `6cc3308f584d5e4713193a494bb4db14000defe5`.
- Ese SHA exacto de `main` pasó BRVTAL CI #799 con conclusión `success`; #457 queda **VALIDATED IN CODE** en `main`.
- Este head debe pasar BRVTAL CI, SonarQube Cloud y CodeRabbit sobre su SHA exacto antes del squash merge; no se reutilizan gates de la preparación apilada anterior ni de SHA previos.
- CI verde significará **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Resolver cualquier finding válido del SHA exacto de #458, hacer squash merge solo con los gates aplicables verdes y verificar BRVTAL CI del SHA exacto resultante de `main`.
- Recontrastar después #459 (DOM API quick wins) contra el nuevo `main`, refrescar su README y repetir sus gates completos.
- Preparar un bloque separado para los HIGH DOM todavía vigentes en `discadmin/bulk-actions.js` y `discadmin/global-search.js`, sin ampliar retrospectivamente #459.
- Continuar #451 por riesgo con los siguientes findings de Reliability / accesibilidad y después Maintainability acotada.
- Mantener #434 (Memories) separado hasta resolver su propio Quality Gate y tratar su migración de producción por separado.

## Panorama general pendiente

Este panorama debe mantenerse actualizado en **cada deploy** y resumir trabajo relevante todavía abierto, aunque no forme parte del deploy actual.

- **DOM API maintainability:** PR #459 — dos HIGH quick wins (`dataset` y `Element.after`) ya implementados; requiere refresh contra el `main` resultante de #458 antes de integración.
- **DOM API siguiente bloque:** `discadmin/bulk-actions.js` y `discadmin/global-search.js` conservan dos HIGH equivalentes de inserción DOM ya revalidados y se tratarán en un PR separado.
- **Memories administrable:** #415 / PR #434 — galería curada desde DISCADMIN usando Media Library existente, con publicación, orden y viewer editorial; falta cerrar Quality Gate y ejecutar la migración de producción por separado.
- **Sonar / calidad:** #451 — los BLOCKER de seguridad/globals, `S2871`, `S7727`, los cuatro `S8786` y el bloque de labels de Blog/Media/Releases están trabajados; continúa la cola por riesgo y área.
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
