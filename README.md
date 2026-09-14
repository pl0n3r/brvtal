# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. Se reemplaza en cada deploy. El contexto durable vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- Se hace persistente la navegación visible de DISCADMIN mediante `?module=<destino>` sin crear rutas ni mini-admins fuera de `/discadmin`.
- Artists, Events, Releases, Sets, Blog, Pages, Media, Hero Slider, Theme Studio, Settings, Security, System Status, Backups y Activity pueden restaurarse desde URL, refresh y Back/Forward.
- `content-core` sigue siendo infraestructura interna: cualquier estado equivalente se canoniza al destino visible `events`.
- Un deep-link conserva su destino incluso si primero exige autenticación y el login heredado intenta abrir Dashboard.
- Dashboard continúa siendo la URL limpia de `/discadmin`; parámetros y hash ajenos al estado del módulo se conservan.

## Archivos modificados en este deploy

- `discadmin/admin-information-architecture.js` — sincroniza el workspace visible con History API, restaura deep-links y preserva el destino a través del login.
- `tests/e2e/discadmin-information-architecture.spec.mjs` — cubre URL state, deep-link, login, Back/Forward, Events guiado y System Status.
- `README.md` — snapshot operativo de este deploy.

## Validación

- El PR debe pasar `BRVTAL CI / validate`, `PHP 8.5 Compatibility / php85` y el gate de README antes del merge.
- La prueba dirigida de Information Architecture debe demostrar que la navegación normal actualiza `?module=`, Back restaura el workspace, los deep-links nativos y Events se recuperan correctamente y el destino sobrevive al login.
- Después del merge se verificará el CI completo del SHA exacto de `main` y el deploy exacto en Hostinger.
- CI verde implica **VALIDATED IN CODE**; observar el SHA exacto en Hostinger implica **DEPLOYED**.

## Qué sigue

1. Continuar simplificando DISCADMIN solo ante fricciones reproducibles del shell/workspace, manteniendo ONE SHELL / ONE SIDEBAR / ONE SESSION / ONE CENTRAL WORKSPACE.
2. No extender Media hacia CONNECTED mientras Media no tenga relaciones estructuradas públicas propias.
3. Mantener #122–#125 abiertos hasta ejecutar sus workflows autenticados y obtener evidencia real de producción.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
