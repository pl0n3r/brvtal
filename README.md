# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí el snapshot exacto de lo que cambió y un panorama general actualizado de lo pendiente.

## Qué se hizo

- Continúa #451 con un bloque P1 de accesibilidad de formularios en DISCADMIN.
- Blog y Releases asocian explícitamente sus labels con inputs, selects y textareas dinámicos.
- La fila de artistas de Releases deja de envolver dos controles dentro de un único `label`; checkbox y rol reciben nombres accesibles independientes. El nombre visible queda asociado nativamente con su checkbox para ampliar el target de interacción.
- Content Core da nombres accesibles a Ticket Types dinámicos, al botón de eliminación y a los checkboxes del lineup. Los nombres visibles del lineup también quedan asociados con su checkbox.
- El editor de lifecycle de Artists asocia cada label con su control.
- El buscador del Media Picker recibe un nombre accesible explícito.
- No cambia payload, validación, persistencia, rutas ni comportamiento editorial.
- Se añade un contrato dedicado que cubre estos nombres y asociaciones.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente.
- `discadmin/blog.js` — asociaciones label/control del editor Blog.
- `discadmin/content-core.js` — nombres accesibles de tickets, lineup y lifecycle de Artists.
- `discadmin/media-library.js` — nombre accesible del buscador del Media Picker.
- `discadmin/releases.js` — asociaciones label/control y separación semántica de controles por artista.
- `tests/discadmin-editor-accessibility-contract.php` — regresión de accesibilidad para los cuatro módulos.

## Validación

- Base exacta: `main` `8d48bd81f7b6aaee79632a8eb99057b2abd7d6dc`.
- Esa base pasó BRVTAL CI #972 y queda **VALIDATED IN CODE**.
- El PR debe pasar BRVTAL CI, SonarCloud y CodeRabbit sobre su SHA exacto antes del squash merge.
- Tras el merge se verificará BRVTAL CI sobre el SHA exacto resultante de `main`.
- CI verde significa **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Cerrar este bloque de #451 y continuar con deuda que siga reproduciéndose en el código actual.
- Reconciliar PR #434 / Memories aparte, sin mezclarlo con calidad de formularios.
- Mantener #479, #480 y #481 como frentes separados de SEO/experiencia pública.

## Panorama general pendiente

- **Sonar / calidad:** #451 continúa por riesgo y área; los S2703, S7727 y S8786 del export original ya no se reproducen en `main`.
- **Apariencia:** #149 — completar Light en módulos modernos.
- **Hero Slider:** #221 — integridad editorial de media; #480 — regresión visual del Hero en desktop.
- **Seguridad editorial / navegación:** #257, #216, #174 y #193.
- **SEO editorial / entrega pública:** #182, #214, #204 y #272 antes de #390; #479 corrige imágenes sin `alt`; #481 integra IndexNow.
- **Content / edición:** #224 y #252 — Ticket Types e integridad de media.
- **Activity / operaciones:** #232 y #195 — historial navegable y cobertura de audit log.
- **Bulk Actions:** #275 — catálogos mayores de 500 sin truncado silencioso.
- **Dashboard / DISCADMIN:** #348 y #351 — IA y Theme Studio.
- **Archivo cultural:** #398 / #403 — Event Records y relaciones estructuradas.
- **Memories:** #415 / PR #434 requiere reconciliación con `main`; ninguna migración de producción automática.
- **Idioma:** #212 — español canónico + inglés automático por fases.
- **Backups:** #389 — scheduling seguro y Drive opcional con autorización externa.
