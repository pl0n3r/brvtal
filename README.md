# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí el snapshot exacto de lo que cambió y un panorama general actualizado de lo pendiente.

## Qué se hizo

- Continúa #451 con un bloque P2 acotado de mantenibilidad en la arquitectura de navegación de DISCADMIN.
- `buttonKey()` deja de mantener una cadena larga de decisiones por etiqueta y pasa a usar un mapa de labels exactos más reglas parciales declarativas.
- Se preserva la prioridad canónica: `data-admin-nav` y handlers `go()/tech()` siguen ganando antes que el texto visible.
- Se preservan los aliases actuales de Media, Hero Slider, Theme, Security / 2FA, System Status, Backups, Activity, Content Core y SEO.
- Las etiquetas desconocidas conservan el fallback `other:<label>`.
- No cambia navegación, URL state, shell, permisos, API, base de datos ni comportamiento editorial.
- La regresión Playwright cubre labels exactos, coincidencias parciales, Content Core oculto y fallback desconocido.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente.
- `discadmin/admin-information-architecture.js` — clasificación declarativa de destinos del sidebar con menor complejidad.
- `tests/e2e/discadmin-information-architecture.spec.mjs` — regresión de aliases y fallbacks de navegación.

## Validación

- Base exacta: `main` `a5ac2980912b364161a0be040ee6cb329a5b430f`.
- Esa base pasó **BRVTAL CI #976** y queda **VALIDATED IN CODE**.
- El PR debe pasar BRVTAL CI, SonarQube Cloud y CodeRabbit sobre su SHA exacto antes del squash merge.
- Tras el merge se verificará BRVTAL CI sobre el SHA exacto resultante de `main`.
- CI verde significa **VALIDATED IN CODE**, no validación de producción.
- Production Performance de la base no midió métricas porque el marcador exacto de Hostinger aún no era visible dentro de su ventana acotada; se solicitó rerun del job fallido.

## Qué sigue

- Cerrar este bloque de #451 y continuar con deuda P2 que siga reproduciéndose en el código actual.
- Reconciliar PR #434 / Memories aparte, sin mezclarlo con este refactor.
- Mantener #479, #480 y #481 como frentes separados de SEO/experiencia pública.

## Panorama general pendiente

- **Sonar / calidad:** #451 continúa por riesgo y área; P1 revalidado ya quedó cubierto o dejó de reproducirse, y el trabajo entra en bloques P2 pequeños.
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
