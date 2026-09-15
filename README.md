# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- El menú fullscreen público ahora inmoviliza el documento de fondo mientras está abierto.
- El lock conserva la posición X/Y y restaura exactamente el scroll al cerrar, evitando saltos de sección tras wheel o touch.
- En desktop enhanced, el módulo captura la instancia de Lenis antes de `app.js`, la pausa al abrir MENU y solo la reanuda si estaba activa previamente.
- En touch, reduced-motion o fallback sin Lenis, el bloqueo nativo sigue funcionando sin depender del stack de motion.
- La semántica, focus trap, Escape y restauración de foco existentes en `menu-accessibility.js` se mantienen sin duplicar responsabilidades.

## Archivos modificados en este deploy

- `js/menu-scroll-lock.js` — nuevo lock de scroll nativo y coordinación segura con Lenis.
- `js/public-runtime-loader.js` — carga el lock antes de `app.js` para poder capturar Lenis cuando existe.
- `tests/e2e/public-menu-accessibility.spec.mjs` — valida lock, posición restaurada, pausa/reanudación de Lenis y accesibilidad existente.
- `tests/e2e/public-mobile-performance.spec.mjs` — incorpora el nuevo módulo al orden versionado del runtime en touch/reduced/enhanced/fallback.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `cabfa8e9eee96f1062c92b9ed474421e1f1751d7`.
- Ese SHA exacto tenía BRVTAL CI completo, PHP 8.5 Compatibility y Backup Recovery Rehearsal en success.
- No hay cambios de esquema, migraciones ni mutaciones de datos de producción.
- El alcance no modifica el comportamiento visual del menú ni su contrato ARIA/foco; únicamente sincroniza el estado abierto con el scroll del documento.
- Pendiente de **BRVTAL CI / validate** y **PHP 8.5 Compatibility / php85** del PR.
- Tras el merge se verificará la matriz completa sobre el SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir en esta misma rama cualquier fallo detectado.
2. Con CI verde, hacer squash merge.
3. Verificar BRVTAL CI completo, PHP 8.5 y Backup Recovery sobre el SHA exacto resultante de `main`.
4. Cerrar #238 y continuar con el siguiente issue público prioritario que siga vigente.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
