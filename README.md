# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí, además del snapshot exacto de lo que cambió, un **panorama general actualizado de lo que sigue pendiente por hacer/desarrollar**.

## Qué se hizo

- Continúa #451 con un bloque acotado de Reliability / accesibilidad en módulos canónicos de DISCADMIN.
- Blog, Media Library y Releases asocian labels reales a los controles de búsqueda/carga que Sonar reportó sin etiqueta válida.
- `discadmin/admin-modules.css` incorpora una utilidad compartida visualmente oculta para mantener esos labels disponibles a lectores de pantalla sin alterar el layout.
- Media Library usa además un elemento nativo `<output>` con anuncio `polite` para el estado, en lugar del contenedor genérico con `role="status"`.
- El contrato de regresión parsea el markup con DOMDocument/DOMXPath y valida estructuralmente las asociaciones label/control, la semántica de `output` y la ausencia del `clip` CSS deprecado, incluso con variantes de whitespace.
- No se modifican APIs, base de datos, navegación ni lógica de negocio.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente actualizado.
- `discadmin/admin-modules.css` — utilidad compartida para labels accesibles visualmente ocultos.
- `discadmin/blog.php` — label asociado a la búsqueda de Blog.
- `discadmin/media-library.php` — labels asociados a búsqueda/carga y estado nativo con `<output>`.
- `discadmin/releases.php` — label asociado a la búsqueda de Releases.
- `tests/admin-search-accessibility-contract.php` — contrato estructural de regresión para las asociaciones y semántica tratadas.

## Validación

- Base exacta recontrastada: `main` `feac972b6f62797ec4f07a2c1dc2f32a1c4a3e97`.
- Ese SHA exacto de `main` pasó BRVTAL CI #795 con conclusión `success`; #456 queda VALIDATED IN CODE en `main`.
- Sobre la preparación anterior de #457, BRVTAL CI #794 y CodeRabbit quedaron verdes tras corregir los findings válidos de `clip`, parsing estructural y tolerancia a whitespace.
- Este head refrescado debe volver a pasar BRVTAL CI, SonarQube Cloud y CodeRabbit sobre su SHA exacto antes del squash merge; no se reutilizan gates de un SHA anterior.
- CI verde significará **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Cerrar los gates del SHA exacto refrescado de #457, resolver cualquier finding válido, hacer squash merge y verificar BRVTAL CI del SHA exacto resultante de `main`.
- Después, recontrastar y retargetear #458 (Content Core accessibility) contra ese nuevo `main`, refrescar README y ejecutar sus gates completos.
- Mantener #459 (DOM API quick wins) en paralelo, pero refrescarlo contra el `main` vigente antes de cualquier merge.
- Continuar #451 por riesgo con los siguientes findings de accesibilidad / Reliability y después Maintainability acotada.
- Mantener #434 (Memories) separado hasta resolver su propio Quality Gate y tratar su migración de producción por separado.

## Panorama general pendiente

Este panorama debe mantenerse actualizado en **cada deploy** y resumir trabajo relevante todavía abierto, aunque no forme parte del deploy actual.

- **Content Core accessibility:** PR #458 — asociaciones de labels y nombres accesibles preparados; revisión manual de CodeRabbit en curso por ser un PR apilado.
- **DOM API maintainability:** PR #459 — dos HIGH quick wins (`dataset` y `Element.after`) ya implementados y con CI/CodeRabbit tempranos verdes; requiere refresco contra `main` antes de integración.
- **Memories administrable:** #415 / PR #434 — galería curada desde DISCADMIN usando Media Library existente, con publicación, orden y viewer editorial; falta cerrar Quality Gate y ejecutar la migración de producción por separado.
- **Sonar / calidad:** #451 — los BLOCKER de seguridad/globals, `S2871`, `S7727` y los cuatro `S8786` identificados están trabajados en código; continúan accesibilidad y deuda Maintainability por riesgo y por área.
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
