# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí el snapshot exacto de lo que cambió y un panorama general actualizado de lo pendiente.

## Qué se hizo

- Continúa #451 con un bloque P2 acotado de mantenibilidad en Media Library.
- `renderInspector()` deja de concentrar la preparación condicional de visual, dimensiones, estado del Media Engine, referencias de uso y contextos de crop.
- Esas decisiones pasan a helpers pequeños de presentación, preservando el HTML y la precedencia funcional existentes.
- El inspector sigue diferenciando imágenes de audio/video/documentos; los assets no-imagen no muestran controles de crop.
- Los assets referenciados siguen mostrando su uso y mantienen DELETE deshabilitado.
- La regresión Playwright cubre explícitamente un asset de audio referenciado además del flujo de imagen/crop existente.
- No cambia API, upload, transformación, persistencia, permisos ni rutas.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente.
- `discadmin/media-library.js` — helpers de presentación para reducir el anidamiento del inspector.
- `tests/e2e/discadmin-media.spec.mjs` — cobertura de inspector no-imagen y protección de media referenciada.

## Validación

- Base exacta: `main` `a957f683b84983ab81173ee3b934e3bea12b865c`.
- Esa base pasó **BRVTAL CI #994** y queda **VALIDATED IN CODE**.
- El PR debe pasar BRVTAL CI, SonarQube Cloud y CodeRabbit sobre su SHA exacto antes del squash merge.
- Tras el merge se verificará BRVTAL CI sobre el SHA exacto resultante de `main`.
- CI verde significa **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Tachar Media Library deep nesting en #451 solo después del merge y del CI verde del `main` exacto.
- Continuar con el siguiente bloque P2 que siga reproduciéndose.
- Revalidar aparte el bloque Sonar de `Math.random()` reportado por el usuario antes de cambiarlo.
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
