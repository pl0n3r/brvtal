# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí, además del snapshot exacto de lo que cambió, un **panorama general actualizado de lo que sigue pendiente por hacer/desarrollar**.

## Qué se hizo

- Continúa #451 con dos HIGH de Maintainability revalidados contra el `main` actual y aislados en un carril independiente.
- `discadmin/admin-record-lists.js` elimina `data-label` mediante `dataset`, conservando exactamente la limpieza previa del atributo y cerrando `javascript:S7761`.
- `js/public-sets-library.js` inserta los controles con `Element.after()`, conservando la misma posición inmediatamente posterior al bloque intro y cerrando `javascript:S7768`.
- Se añade un contrato fuente auto-descubierto que bloquea la reintroducción de ambos patrones reportados.
- No se cambian APIs, datos, rutas, orden editorial, estilos ni comportamiento visible.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente actualizado.
- `discadmin/admin-record-lists.js` — eliminación de `data-label` mediante `dataset`.
- `js/public-sets-library.js` — inserción equivalente de controles mediante `Element.after()`.
- `tests/sonar-dom-api-quick-wins-contract.php` — contrato de regresión para los dos findings Sonar tratados.

## Validación

- Base exacta de preparación: `main` `c5c713ec94e344e753600d2315bff2bde7debbc5`.
- Ese SHA exacto de `main` pasó BRVTAL CI #771 con `fast`, Chromium y `validate` verdes.
- La rama debe pasar BRVTAL CI, SonarQube Cloud y CodeRabbit sobre su SHA exacto antes de ser elegible para merge.
- Como existen PRs anteriores en la cola, antes del merge se recontrastará contra el `main` resultante, se actualizará este snapshot y se repetirán los gates aplicables.
- CI verde significará **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Mantener el orden de integración serial de los PRs activos y aprovechar CI/Sonar/CodeRabbit en paralelo.
- Resolver cualquier finding válido de este PR sin ampliar el alcance más allá de los quick wins DOM.
- Continuar #451 con los HIGH restantes de Maintainability por riesgo, separando refactors de complejidad de cambios mecánicos de bajo riesgo.
- Mantener #434 (Memories) separado hasta resolver su propio Quality Gate y tratar su migración de producción por separado.

## Panorama general pendiente

Este panorama debe mantenerse actualizado en **cada deploy** y resumir trabajo relevante todavía abierto, aunque no forme parte del deploy actual.

- **Theme Studio / calidad:** PR #456 — cierre del cuarto `S8786` y validación server-side del slug; pendiente cerrar gates exactos y merge serial.
- **Accesibilidad DISCADMIN:** PR #457 — Blog, Media y Releases; PR #458 apilado — Content Core labels; ambos requieren recontraste final contra el `main` que resulte de los PRs anteriores.
- **Memories administrable:** #415 / PR #434 — galería curada desde DISCADMIN usando Media Library existente; falta cerrar Quality Gate y ejecutar la migración de producción por separado.
- **Sonar / calidad:** #451 — BLOCKER y HIGH de Reliability tratados; continúan HIGH/MEDIUM de Maintainability y Reliability restante por riesgo y área.
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
