# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí, además del snapshot exacto de lo que cambió, un **panorama general actualizado de lo que sigue pendiente por hacer/desarrollar**.

## Qué se hizo

- Continúa #451 con dos HIGH de Maintainability `javascript:S7768` revalidados en DISCADMIN.
- `discadmin/bulk-actions.js` reemplaza `top.insertBefore(trigger, status)` por `status.before(trigger)` preservando que el trigger quede inmediatamente antes del indicador de estado.
- `discadmin/global-search.js` aplica el mismo reemplazo equivalente y conserva el orden actual del toolbar.
- Ambas funciones `ensureTrigger()` reciben un docblock breve y específico al contrato que mantienen.
- Se añade un contrato fuente que exige `status.before(trigger)` y rechaza la forma legacy de `insertBefore` en ambos archivos.
- Se añade cobertura Playwright que ejecuta cada script y verifica que su trigger quede realmente como hermano inmediatamente anterior a `.status`.
- No se modifican APIs, base de datos, rutas, permisos, estilos ni comportamiento visible.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente actualizado.
- `discadmin/bulk-actions.js` — modernización equivalente de inserción DOM del trigger.
- `discadmin/global-search.js` — modernización equivalente de inserción DOM del trigger.
- `tests/sonar-dom-before-quick-wins-contract.php` — contrato dirigido contra la regresión `S7768`.
- `tests/e2e/discadmin-trigger-order.spec.mjs` — regresión ejecutable del orden DOM de ambos triggers.

## Validación

- Base exacta recontrastada: `main` `1ff5a6cb2a1f7967482b41a4dfb0c40a818c5a9b`.
- Ese SHA exacto pasó BRVTAL CI #812 con `fast`, `chromium` y `validate` en `success`; #462 queda **VALIDATED IN CODE** en `main`.
- El diff fue preflightado contra `main`: cada JS solo cambia el docblock de `ensureTrigger()` y la API de inserción equivalente; el contrato y la prueba Playwright son archivos nuevos.
- Este head debe pasar BRVTAL CI, SonarQube Cloud y CodeRabbit sobre su SHA exacto antes del squash merge.
- CI verde significará **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Resolver cualquier finding válido del PR, hacer squash merge únicamente con los gates exactos verdes y verificar BRVTAL CI del SHA resultante de `main`.
- Recontrastar el baseline vigente de #451 y escoger el siguiente bloque HIGH por riesgo/área, evitando refactors amplios.
- Mantener #434 (Memories) separado hasta resolver su propio Quality Gate y tratar su migración de producción por separado.

## Panorama general pendiente

Este panorama debe mantenerse actualizado en **cada deploy** y resumir trabajo relevante todavía abierto, aunque no forme parte del deploy actual.

- **DOM API maintainability:** este deploy cierra los dos HIGH `S7768` revalidados en Bulk Actions y Global Search con contrato fuente y cobertura Playwright de comportamiento.
- **Sonar / calidad:** #451 — continuar la cola por riesgo y área; tras este bloque, revalidar los HIGH restantes de complejidad/mantenibilidad contra el `main` vigente antes de tocar código.
- **Memories administrable:** #415 / PR #434 — galería curada desde DISCADMIN usando Media Library existente, con publicación, orden y viewer editorial; falta cerrar Quality Gate y ejecutar la migración de producción por separado.
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
