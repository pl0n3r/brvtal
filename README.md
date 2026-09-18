# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí el snapshot exacto de lo que cambió y un panorama general actualizado de lo pendiente.

## Qué se hizo

- Continúa #451 con un bloque P2 acotado de mantenibilidad en `discadmin/admin-modules.js`.
- La selección de mensajes de progreso/éxito de mutaciones deja de depender de una cadena larga de decisiones y pasa a reglas declarativas ordenadas.
- Se preserva exactamente la prioridad actual por endpoint, acción y método: Media Library, Releases, Blog, TOTP, DELETE genérico, Settings y fallback general.
- Los textos visibles se mantienen sin cambios.
- La regresión Playwright verifica los mensajes finales y la precedencia de Media upload, Releases delete, Blog save, Settings save, DELETE genérico y save genérico usando el harness real de `admin-modules.js`.
- Los helpers tocados quedan documentados con JSDoc para cerrar el warning válido de cobertura de docstrings reportado por CodeRabbit.
- No cambia API, payloads, persistencia, permisos, rutas ni comportamiento editorial.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente.
- `discadmin/admin-modules.js` — reglas declarativas para feedback de mutaciones con menor complejidad.
- `tests/e2e/discadmin-media.spec.mjs` — cobertura conductual de mensajes y precedencia de feedback.

## Validación

- Base exacta: `main` `fcb7d43ac771f64434b817476340bd067e278d68`.
- Esa base pasó **BRVTAL CI #978** (`fast`, Chromium y `validate`) y queda **VALIDATED IN CODE**.
- El PR debe pasar BRVTAL CI, SonarQube Cloud y CodeRabbit sobre su SHA exacto antes del squash merge.
- Tras el merge se verificará BRVTAL CI sobre el SHA exacto resultante de `main`.
- CI verde significa **VALIDATED IN CODE**, no validación de producción.
- Production Performance reciente sigue sin constituir evidencia de regresión: la medición no arrancó porque el marcador exacto de deploy de Hostinger no fue observado dentro de su ventana acotada.

## Qué sigue

- Cerrar este bloque de #451 y continuar con deuda P2 que siga reproduciéndose en el código actual.
- Reconciliar PR #434 / Memories aparte, sin mezclarlo con calidad interna.
- Mantener #479, #480 y #481 como frentes separados de SEO/experiencia pública.

## Panorama general pendiente

- **Sonar / calidad:** #451 continúa por riesgo y área; P1 ya quedó cubierto o dejó de reproducirse y los P2 se están resolviendo en PRs pequeños.
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
