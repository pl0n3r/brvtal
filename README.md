# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí el snapshot exacto de lo que cambió y un panorama general actualizado de lo pendiente.

## Qué se hizo

- Continúa #451 con un bloque de accesibilidad confirmado por Sonar en DISCADMIN.
- El modal legacy deja de renderizar un `<h2>` vacío y ahora expone un título inicial accesible; los flujos existentes siguen reemplazándolo dinámicamente al abrir cada editor.
- Media Library deja de usar `tabindex` sobre un `div` y convierte el dropzone en un `button type="button"` nativo.
- El dropzone conserva drag/drop y activación por teclado; la activación nativa abre el selector de archivos mediante el mismo input existente.
- El CSS conserva el layout visual del dropzone tras el cambio de elemento.
- Se añade cobertura estática y Playwright para heading no vacío, semántica nativa del dropzone y activación por Enter.
- No cambia API, upload, persistencia, permisos, rutas ni comportamiento editorial.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente.
- `discadmin/index-core.php` — título inicial accesible para el modal legacy.
- `discadmin/media-library.php` — dropzone convertido a botón nativo sin `tabindex`.
- `discadmin/media-library.js` — activación nativa por click en lugar de emulación manual de teclado.
- `discadmin/media-library.css` — preserva ancho, tipografía y alineación del dropzone nativo.
- `tests/e2e/discadmin-initial-media.spec.mjs` — prueba de semántica y activación por teclado del dropzone.
- `tests/e2e/discadmin-keyboard-modal-quick-wins.spec.mjs` — contratos de heading y dropzone accesibles.

## Validación

- Base exacta: `main` `ed62896a8bdc8a49c1c38a91d8b82d8b0973b8aa`.
- Esa base pasó **BRVTAL CI #999** y queda **VALIDATED IN CODE**.
- El PR debe pasar BRVTAL CI, SonarQube Cloud y CodeRabbit sobre su SHA exacto antes del squash merge.
- Tras el merge se verificará BRVTAL CI sobre el SHA exacto resultante de `main`.
- CI verde significa **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Tachar este bloque de accesibilidad en #451 solo después del merge y del CI verde del `main` exacto.
- Continuar con los findings Sonar reportados por el usuario, incluyendo `Math.random()`, `replaceAll()` y la configuración `sonar.python.version`, cada uno en scope separado.
- Reconciliar PR #434 / Memories aparte, sin ejecutar migraciones de producción automáticamente.
- Mantener #479, #480 y #481 como frentes separados.

## Panorama general pendiente

- **Sonar / calidad:** #451 continúa por riesgo y área; los bloques completados se tachan únicamente tras merge + exact-main CI.
- **Apariencia:** #149 — completar Light en módulos modernos.
- **Hero Slider:** #221 — integridad editorial de media; #480 — regresión visual del Hero en desktop. #237 ya está cerrado.
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
