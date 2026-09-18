# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí el snapshot exacto de lo que cambió y un panorama general actualizado de lo pendiente.

## Qué se hizo

- Implementa #388 dentro de System Status → Advanced Diagnostics.
- Añade una acción destructiva y claramente diferenciada **RESET LOG** junto a los controles de logs.
- La acción exige confirmación explícita de dos pasos en el propio botón, usa POST autenticado con CSRF y reutiliza `discadmin/logs.php?action=clear` como única frontera de borrado.
- `logs.php` conserva el flujo HTML existente y añade una respuesta JSON opcional para el workspace interno; no se duplica lógica de filesystem en `technical.php`.
- El borrado usa `LOCK_EX` y devuelve error explícito si el archivo no puede vaciarse.
- Tras éxito, System Status vuelve a leer el log y actualiza contenido, líneas y bytes; un fallo de reset conserva el estado visible y muestra error.
- Se añade estilo de peligro/focus visible y cobertura Playwright para primer clic sin mutación, confirmación, éxito y fallo.
- Se añade contrato PHP para mantener POST-only, CSRF, locking y reutilización del endpoint canónico.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente.
- `discadmin/logs.php` — respuesta JSON opcional y fallo explícito del reset, preservando la UI standalone.
- `discadmin/system-status-v2.js` — RESET LOG, confirmación de dos pasos, CSRF, loading/error y refresh del estado visible.
- `discadmin/system-status-v2.css` — agrupación de acciones, estilo destructivo y foco visible.
- `discadmin/technical.php` — metadata de bytes para el log leído por System Status.
- `tests/e2e/discadmin-system-status-reset-log.spec.mjs` — regresiones de primer clic sin mutación, confirmación, éxito y fallo.
- `tests/system-status-reset-log-contract.php` — contrato de seguridad y arquitectura del reset.

## Validación

- Base exacta: `main` `96d39ba580a1f4e0bd33907f38378cbc33a82642`.
- Ese SHA exacto pasó BRVTAL CI #865 y queda **VALIDATED IN CODE**.
- El head del PR debe pasar BRVTAL CI, SonarCloud y CodeRabbit sobre su SHA exacto antes del squash merge.
- Tras el merge se verificará BRVTAL CI sobre el SHA exacto resultante de `main`.
- CI verde significa **VALIDATED IN CODE**, no validación de producción.
- No se ejecutan operaciones destructivas en producción durante esta validación.

## Qué sigue

- Cerrar gates exactos de #388, corregir findings válidos, squash merge y verificar el nuevo `main`.
- Atacar #365: hacer Dashboard V2 autoritativo para Health/Activity sin perder navegación al registro exacto.
- Continuar después con #191 y #237 como bloques independientes de tamaño pequeño/medio.
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
- **Completados recientemente:** #207, #391 y #260 ya fueron revalidados/cerrados en código.
