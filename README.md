# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí, además del snapshot exacto de lo que cambió, un **panorama general actualizado de lo que sigue pendiente por hacer/desarrollar**.

## Qué se hizo

- Continúa #451 con dos HIGH de Maintainability (`javascript:S7768`) revalidados en DISCADMIN.
- `discadmin/bulk-actions.js` reemplaza `top.insertBefore(trigger, status)` por `status.before(trigger)` al montar el trigger de Bulk Actions.
- `discadmin/global-search.js` aplica el mismo reemplazo al trigger de Global Search.
- `status.before(trigger)` conserva exactamente el orden DOM anterior: el trigger queda inmediatamente antes del nodo de estado; si no existe status se conserva `top.appendChild(trigger)`.
- Se documentan las dos funciones `ensureTrigger()` tocadas y se añade un contrato dirigido que exige el patrón moderno e impide restaurar el `insertBefore` anterior.
- No se modifican APIs, base de datos, rutas, permisos, búsquedas, operaciones bulk, estilos ni comportamiento visible.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente actualizado.
- `discadmin/bulk-actions.js` — inserción moderna y equivalente del trigger de Bulk Actions.
- `discadmin/global-search.js` — inserción moderna y equivalente del trigger de Global Search.
- `tests/sonar-dom-before-contract.php` — contrato dirigido contra la regresión de ambos patrones S7768.

## Validación

- Base exacta recontrastada: `main` `fb4dc334d150e18e514e8b8b6f7a82507a54a19b`.
- Ese SHA exacto pasó BRVTAL CI #816 con `fast`, `chromium` y `validate` en `success`; #466 queda **VALIDATED IN CODE** en `main`.
- El head anterior de este PR fue evaluado sobre una base previa, pero esos resultados no se reutilizan tras este refresh.
- El head final refrescado debe pasar nuevamente BRVTAL CI, SonarQube Cloud y CodeRabbit sobre su SHA exacto antes del squash merge.
- Después del merge se verificará BRVTAL CI sobre el SHA exacto resultante de `main`; CI verde significará **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Completar los gates del head exacto de #465, resolver findings válidos, hacer squash merge y verificar el CI exacto del nuevo `main`.
- Continuar #451 por riesgo con los siguientes findings vigentes, siempre recontrastados contra el código actual y en bloques pequeños.
- Mantener #434 (Memories) separado hasta resolver su propio Quality Gate y tratar su migración de producción por separado.

## Panorama general pendiente

Este panorama debe mantenerse actualizado en **cada deploy** y resumir trabajo relevante todavía abierto, aunque no forme parte del deploy actual.

- **DOM API maintainability:** #461 y #462 ya están VALIDATED IN CODE; #466 cerró la advertencia documental restante; #465 aborda los dos HIGH S7768 de triggers administrativos pendientes.
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
