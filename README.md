# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí, además del snapshot exacto de lo que cambió, un **panorama general actualizado de lo que sigue pendiente por hacer/desarrollar**.

## Qué se hizo

- Se cierra el único finding válido de CodeRabbit posterior a #461 sin modificar lógica de producción.
- La cobertura Playwright de record lists ahora fuerza decoraciones repetidas y comprueba que metadata `data-label` obsoleta se elimine antes de reconstruir el esquema responsive.
- La cobertura Playwright de la librería pública de Sets ahora comprueba que `.sets-library-controls` quede inmediatamente después de `.sets-intro`, preservando la semántica de `Element.after()` entregada por #461.
- Los contratos fuente de #461 permanecen intactos; este deploy añade evidencia ejecutable del comportamiento real solicitado por revisión.
- No se modifican APIs, base de datos, rutas, estilos ni código de producción.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente actualizado.
- `tests/e2e/discadmin-mobile-record-lists.spec.mjs` — regresión para limpieza de `data-label` en decoraciones repetidas.
- `tests/e2e/public-sets-library-phase-c.spec.mjs` — regresión para posición de controles inmediatamente después de `.sets-intro`.

## Validación

- Base exacta: `main` `d0d65de8131889f20400df53587b24d7164d23b5`.
- Ese SHA exacto pasó BRVTAL CI #809 con conclusión `success`; #461 queda **VALIDATED IN CODE** en `main`.
- Production Performance #497 también concluyó `success`, pero esto no se considera por sí solo validación funcional de producción.
- Este head debe pasar BRVTAL CI, SonarQube Cloud y CodeRabbit sobre su SHA exacto antes del squash merge.
- CI verde significará **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Integrar este follow-up de cobertura solo con gates exactos verdes y verificar el SHA exacto resultante de `main`.
- Preparar en un PR separado los HIGH DOM todavía vigentes en `discadmin/bulk-actions.js` y `discadmin/global-search.js` usando `status.before(trigger)` para preservar el orden actual.
- Continuar #451 por riesgo con los siguientes findings vigentes, recontrastados siempre contra `main`.
- Mantener #434 (Memories) separado hasta resolver su propio Quality Gate y tratar su migración de producción por separado.

## Panorama general pendiente

Este panorama debe mantenerse actualizado en **cada deploy** y resumir trabajo relevante todavía abierto, aunque no forme parte del deploy actual.

- **Cobertura DOM:** follow-up de #461 — cerrar la evidencia ejecutable solicitada por CodeRabbit para record lists y Sets.
- **DOM API siguiente bloque:** `discadmin/bulk-actions.js` y `discadmin/global-search.js` conservan dos HIGH equivalentes de inserción DOM; el reemplazo correcto y equivalente es `status.before(trigger)`.
- **Memories administrable:** #415 / PR #434 — galería curada desde DISCADMIN usando Media Library existente, con publicación, orden y viewer editorial; falta cerrar Quality Gate y ejecutar la migración de producción por separado.
- **Sonar / calidad:** #451 — continuar la cola por riesgo y área, evitando refactors masivos y revalidando cada finding contra el código vigente.
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
