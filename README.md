# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

## Qué se hizo

- Implementa #388 dentro de System Status → Advanced Diagnostics.
- Añade **RESET LOG** con confirmación explícita de dos pasos antes de cualquier mutación.
- El reset reutiliza `discadmin/logs.php?action=clear`, exige sesión admin + POST + CSRF y conserva el comportamiento standalone existente.
- La política destructiva se extrae a un helper reutilizado por el endpoint y probado sobre un archivo temporal aislado; no se toca el log real durante tests.
- El borrado usa `LOCK_EX`, reporta fallos de escritura y refresca contenido, líneas y bytes tras éxito.
- Las lecturas/reset comparten una generación de operación para impedir que una respuesta previa al reset sobrescriba el estado ya limpiado.
- Si el reset falla por CSRF/autenticación, el token cacheado se invalida para que el siguiente intento consulte un token actual.
- La cobertura Chromium incluye confirmación, éxito, fallo, carrera de respuesta vieja y recuperación tras CSRF obsoleto.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente.
- `config/admin_log.php` — política testable y aislada para validar/ejecutar el clear del log.
- `discadmin/logs.php` — frontera autenticada/CSRF del reset y respuesta JSON opcional.
- `discadmin/system-status-v2.css` — agrupación de acciones, estado destructivo y foco visible.
- `discadmin/system-status-v2.js` — RESET LOG, confirmación, refresh, serialización de operaciones y recuperación de CSRF.
- `discadmin/technical.php` — metadata de bytes/lectura defensiva del log.
- `tests/e2e/discadmin-system-status-reset-log.spec.mjs` — regresiones de UI, concurrencia y token CSRF.
- `tests/system-status-reset-log-contract.php` — contrato ejecutable con archivo temporal auto-limpiable.

## Validación

- Base exacta: `main` `96d39ba580a1f4e0bd33907f38378cbc33a82642`.
- Esa base pasó BRVTAL CI #865 y queda **VALIDATED IN CODE**.
- El head final del PR debe volver a pasar BRVTAL CI, SonarCloud y CodeRabbit después de los fixes de review.
- Tras squash merge se verificará BRVTAL CI sobre el SHA exacto resultante de `main`.
- CI verde significa **VALIDATED IN CODE**, no validación de producción.
- Ningún test ni paso automático limpia el log de producción.

## Qué sigue

- Cerrar gates exactos de #388, resolver findings válidos, squash merge y verificar el nuevo `main`.
- Continuar con #365: Dashboard V2 autoritativo para Health/Activity con navegación al registro exacto.
- Después continuar #191 y #237 como bloques independientes.
- Mantener #451 como burn-down continuo con cambios pequeños revalidados contra el código actual.

## Panorama general pendiente

- **Sonar / calidad:** #451 continúa por riesgo y área, con PRs pequeños.
- **Dashboard / DISCADMIN:** #365, #348 y #351 — autoridad del Dashboard, IA y Theme Studio.
- **Settings / Theme:** #191 — integridad referencial de `theme.active`.
- **Apariencia:** #149 — completar Light en módulos modernos.
- **Hero Slider:** #237 y #221 — breakpoint responsive e integridad de media.
- **Seguridad editorial / navegación:** #257, #216, #174 y #193.
- **SEO editorial:** #182, #214, #204 y #272 antes de #390.
- **Content / edición:** #224 y #252 — Ticket Types e integridad de media.
- **Activity / operaciones:** #232 y #195 — historial navegable y cobertura de audit log.
- **Bulk Actions:** #275 — resolver catálogos mayores de 500 sin truncado silencioso.
- **Archivo cultural:** #398 / #403 — Event Records y relaciones estructuradas.
- **Memories:** #415 / PR #434 requiere reconciliación con `main`; ninguna migración de producción automática.
- **Idioma:** #212 — español canónico + inglés automático por fases.
- **Backups:** #389 — scheduling seguro y Drive opcional con autorización externa.
