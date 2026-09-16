# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Sets hidrata Artists y Events antes de abrir el editor, incluso desde accesos directos que no pasan primero por la vista de Sets.
- Logout ya no presenta una falsa sesión cerrada cuando el servidor no confirma la revocación; ante fallo mantiene la sesión local visible y muestra el error.
- Las claves de Settings existentes quedan en solo lectura durante edición para evitar crear accidentalmente una segunda setting con otro nombre.
- Los deep links legacy `backups` y `activity` se canonicalizan a sus workspaces reales: System Status y Dashboard.
- Dashboard y Content Health comparten la regla pública de Pages: solo `published + locale=en` cuenta como contenido público.
- Se añadieron regresiones de contrato y Playwright para cubrir estos Quick Wins como un solo deploy.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo exacto de este deploy.
- `api/content-health.php` — reutiliza la política canónica de visibilidad pública para Pages.
- `api/dashboard-overview.php` — excluye Pages publicadas fuera del locale público inglés.
- `config/public_visibility.php` — define la política canónica `brvtal_public_page_is_visible()`.
- `discadmin/admin-reliability.js` — endurece referencias de Sets, logout y edición de claves de Settings.
- `discadmin/admin-route-aliases.js` — canonicaliza rutas legacy sin crear workspaces paralelos.
- `discadmin/index.php` — carga la capa de aliases después de la IA canónica.
- `tests/discadmin-quick-wins-contract.php` — protege contratos de routing, Settings y visibilidad pública.
- `tests/e2e/discadmin-quick-wins.spec.mjs` — prueba comportamiento de Sets, logout, Settings y aliases.

## Validación

- Base exacta: `main` `4e5386e2c9e3f353a903657fbcdf32094f4525ac`, con `BRVTAL CI / validate` verde.
- No había PRs abiertos al crear `fix/discadmin-quick-wins`.
- Issues cubiertos: `#124`, `#162`, `#184`, `#185`, `#366`.
- No hay migración de base de datos, borrado masivo ni mutación de datos de producción.
- La política pública de Pages coincide con la entrega canónica ya existente: `status='published' AND locale='en'`.
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
- Issues abordados: `#124`, `#162`, `#184`, `#185`, `#366`.
