# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Hero Slider ofrece una interacción equivalente de teclado para reordenar slides: `Alt+↑` y `Alt+↓` sobre la fila enfocada, con `aria-keyshortcuts` visible para tecnologías asistivas.
- DISCADMIN incorpora una frontera común de autenticación: cualquier `401` same-origin de APIs administrativas invalida la sesión local, limpia CSRF y devuelve el shell al login, cubriendo Media, Releases, Blog y módulos dinámicos equivalentes.
- System Status deja de presentar capacidad del filesystem del host como si fuera la cuota de BRVTAL cuando fallan las métricas administradas; muestra `UNAVAILABLE` y limita reintentos automáticos.
- Backups deja de recortar silenciosamente la colección a los ocho elementos más recientes y vuelve accesibles todos los backups retenidos por el backend.
- Se añadieron regresiones PHP y Playwright para los cuatro Quick Wins administrativos.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo exacto de este deploy.
- `discadmin/admin-auth-boundary.js` — política central de expiración de sesión para requests administrativos directos.
- `discadmin/backups.js` — renderiza la colección completa de backups retenidos.
- `discadmin/hero-slider-accessibility.js` — añade reorder de slides por teclado sin reestructurar el editor completo.
- `discadmin/index.php` — carga las nuevas capas de reliability versionadas dentro del shell canónico.
- `discadmin/system-status-storage.js` — muestra estado administrado degradado y aplica throttle a reintentos fallidos.
- `tests/admin-reliability-quick-wins-contract.php` — protege los cuatro contratos del batch.
- `tests/e2e/admin-reliability-quick-wins.spec.mjs` — valida expiración de sesión, reorder por teclado, storage degradado y colección completa de Backups.

## Validación

- Base exacta: `main` `a40ee640b12459f231131a9178a630d8742c2520`, con `BRVTAL CI / validate` verde.
- No había PRs abiertos al crear `fix/admin-reliability-quick-wins`.
- Issues cubiertos: `#177`, `#207`, `#215`, `#265`.
- No hay migración de base de datos, cambios de schema, restore, delete ni mutación de datos de producción.
- La corrección de sesión se implementa en el shell compartido para evitar tres clientes HTTP con políticas divergentes.
- La corrección de Hero Slider usa una interacción de teclado equivalente y no reescribe el módulo visual completo.
- Las regresiones nuevas quedan dentro de las suites automáticas PHP 8.5 + Chromium.
- Pendiente en este snapshot: `BRVTAL CI / validate`, revisión advisory de CodeRabbit y análisis automático de SonarQube Cloud sobre el head final.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobar el deploy real y la superficie correspondiente.

## Qué sigue

1. Resolver en esta misma rama cualquier finding válido de BRVTAL CI, CodeRabbit o SonarQube Cloud.
2. Hacer squash merge solo con `BRVTAL CI / validate` verde.
3. Verificar `BRVTAL CI / validate` del SHA exacto resultante en `main`.
4. Continuar con otro lote de varios Quick Wins compatibles antes de entrar en fixes de mayor profundidad.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de validación: `docs/TESTING.md`.
- Issues abordados: `#177`, `#207`, `#215`, `#265`.
