# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí, además del snapshot exacto de lo que cambió, un **panorama general actualizado de lo que sigue pendiente por hacer/desarrollar**.

## Qué se hizo

- Continúa #451 con un bloque pequeño de Reliability / accesibilidad en módulos canónicos de DISCADMIN.
- `discadmin/blog.php` da nombre accesible a la búsqueda editorial.
- `discadmin/media-library.php` da nombre accesible a la búsqueda y al input de carga, y reemplaza el contenedor genérico `role="status"` por el elemento semántico `output`.
- `discadmin/releases.php` da nombre accesible a la búsqueda del catálogo.
- Se añade un contrato fuente auto-descubierto que protege estos nombres accesibles y la semántica del estado de Media.
- Este PR no cambia lógica de APIs, datos, navegación ni comportamiento de negocio.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente actualizado.
- `discadmin/blog.php` — nombre accesible para la búsqueda de Blog.
- `discadmin/media-library.php` — nombres accesibles para controles de Media y estado nativo con `output`.
- `discadmin/releases.php` — nombre accesible para la búsqueda de Releases.
- `tests/admin-search-accessibility-contract.php` — contrato de regresión para los hallazgos de accesibilidad tratados.

## Validación

- Base exacta de preparación: `main` `c5c713ec94e344e753600d2315bff2bde7debbc5`.
- Ese SHA exacto de `main` pasó BRVTAL CI #771 con `fast`, Chromium y `validate` verdes.
- La rama debe pasar BRVTAL CI, SonarQube Cloud y CodeRabbit sobre el SHA exacto del PR.
- Como #456 sigue delante en la cola de merge, este PR se recontrastará con el `main` resultante y se actualizará este snapshot antes de cualquier merge.
- CI verde significará **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Resolver cualquier finding válido de CI, Sonar o CodeRabbit de este PR mientras #456 termina sus gates.
- Después del squash merge de #456 y de validar su SHA exacto en `main`, recontrastar esta rama contra el nuevo `main`, actualizar este README y volver a ejecutar los gates aplicables antes de mergear.
- Mantener `quality/content-core-form-accessibility` como siguiente carril preparado, sin abrir un tercer PR deploy-bound hasta liberar uno de los dos slots activos.
- Continuar #451 por riesgo con los siguientes findings Reliability antes de deuda puramente estilística.
- Mantener #434 (Memories) separado hasta resolver su propio Quality Gate y tratar su migración de producción por separado.

## Panorama general pendiente

Este panorama debe mantenerse actualizado en **cada deploy** y resumir trabajo relevante todavía abierto, aunque no forme parte del deploy actual.

- **Theme Studio / Sonar:** PR #456 — cuarto `S8786` aislado, con CI y CodeRabbit en revisión final; debe cerrar Sonar y pasar por squash merge antes que este PR.
- **Content Core accessibility:** rama `quality/content-core-form-accessibility` — asociaciones de 14 labels y nombres accesibles de las 2 búsquedas ya preparadas; falta entrar al slot de PR y pasar todos los gates.
- **Memories administrable:** #415 / PR #434 — galería curada desde DISCADMIN usando Media Library existente, con publicación, orden y viewer editorial; falta cerrar Quality Gate y ejecutar la migración de producción por separado.
- **Sonar / calidad:** #451 — los BLOCKER de seguridad/globals, `S2871`, `S7727` y los primeros `S8786` ya se trabajaron; continuar Reliability y luego Maintainability por riesgo y área.
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
