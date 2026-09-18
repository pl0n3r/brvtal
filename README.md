# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí el snapshot exacto de lo que cambió y un panorama general actualizado de lo pendiente.

## Qué se hizo

- Corrige #191 haciendo `theme.active` una referencia válida en lugar de texto libre.
- El backend rechaza activar slugs inexistentes con `THEME_NOT_FOUND` y conserva la validación sintáctica existente.
- La referencia solo se considera válida cuando existe `theme.<slug>`, está marcada como JSON y decodifica a un objeto/array de configuración válido.
- No se puede borrar el registro `theme.<slug>` que está actualmente referenciado por `theme.active`; el servidor responde `ACTIVE_THEME_DELETE_BLOCKED`.
- Tampoco se puede degradar o corromper la definición `theme.<slug>` que está activa: mientras esté referenciada debe conservar `is_json=1` y un payload JSON de configuración válido.
- Las mutaciones `theme.*` se serializan entre sesiones con un mutex de MariaDB y transacción; la validación, el bloqueo de filas y la escritura/borrado ya no pueden intercalarse dejando una referencia colgante.
- `DELETE /settings` usa el mismo normalizador canónico que `POST`, por lo que variantes como `Theme.core` se rechazan antes de tocar la base de datos.
- El fallback `core` sin `theme.active` sigue intacto: Theme Studio guarda primero `theme.core` antes de persistir `theme.active=core` cuando se activa explícitamente.
- Settings deja de ofrecer RAW EDIT para `theme.active` y lo dirige a Theme Studio dentro del mismo shell.
- Se añaden regresiones PHP, Playwright y real-stack autenticadas para activación inexistente, actualización inválida, borrado protegido y navegación desde Settings.
- Se conserva ONE SHELL / ONE SIDEBAR / ONE SESSION / ONE CENTRAL WORKSPACE.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente.
- `api/content-validation.php` — contrato de referencia activa y protección contra borrado del theme activo.
- `api/index.php` — validación server-side del target, mutex/transacción compartidos y bloqueo seguro de borrado.
- `discadmin/settings-v2.js` — `theme.active` se administra desde Theme Studio, no desde editor raw.
- `tests/theme-active-reference-contract.php` — regresión de integridad referencial, serialización y normalización del DELETE.
- `tests/api-contract.php` — conserva la detección del branch DELETE de Settings tras hacerlo legible.
- `tests/content-validation-contract.php` — mantiene los marcadores de validación de Settings tras el formateo.
- `tests/e2e/discadmin-settings-theme-active.spec.mjs` — regresión de navegación desde Settings.
- `tests/e2e/theme-active-reference-real-stack.spec.mjs` — regresión autenticada sobre MariaDB aislada para la integridad de `theme.active`.
- `tests/e2e/run-content-core-real-stack.sh` — incorpora la regresión de Settings al gate real-stack.

## Validación

- Base exacta: `main` `84b69d8c011f5391d63be3f225279edeeb7ce7df`.
- Esa base pasó BRVTAL CI #945 y queda **VALIDATED IN CODE**.
- El PR debe pasar BRVTAL CI, SonarCloud y CodeRabbit sobre su SHA exacto antes del squash merge.
- El relay de Sonar debe publicar las annotations detalladas del head exacto del PR.
- Tras el merge se verificará BRVTAL CI sobre el SHA exacto resultante de `main`.
- CI verde significa **VALIDATED IN CODE**, no validación de producción.
- No se ejecutan migraciones ni operaciones destructivas de producción.

## Qué sigue

- Cerrar gates exactos de #191, corregir findings válidos, squash merge y verificar el nuevo `main`.
- Después atacar #237: assets/overrides del Hero al cambiar breakpoint.
- Mantener #451 como burn-down continuo con cambios pequeños revalidados contra el código actual.
- Reconciliar PR #434 / Memories aparte, sin mezclarlo con este cambio.

## Panorama general pendiente

- **Sonar / calidad:** #451 continúa por riesgo y área, con PRs pequeños.
- **Apariencia:** #149 — completar Light en módulos modernos.
- **Hero Slider:** #237 y #221 — breakpoint responsive e integridad de media.
- **Seguridad editorial / navegación:** #257, #216, #174 y #193.
- **SEO editorial / entrega pública:** #182, #214, #204 y #272 antes de #390; #479 corrige imágenes públicas sin `alt` reportadas por Bing.
- **Content / edición:** #224 y #252 — Ticket Types e integridad de media.
- **Activity / operaciones:** #232 y #195 — historial navegable y cobertura de audit log.
- **Bulk Actions:** #275 — resolver catálogos mayores de 500 sin truncado silencioso.
- **Dashboard / DISCADMIN:** #348 y #351 — IA y Theme Studio.
- **Archivo cultural:** #398 / #403 — Event Records y relaciones estructuradas.
- **Memories:** #415 / PR #434 requiere reconciliación con `main`; ninguna migración de producción automática.
- **Idioma:** #212 — español canónico + inglés automático por fases.
- **Backups:** #389 — scheduling seguro y Drive opcional con autorización externa.
- **Completados recientemente:** #207, #260, #365, #388 y #391 ya fueron revalidados/cerrados en código.
