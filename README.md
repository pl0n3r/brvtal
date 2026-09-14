# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. Se reemplaza en cada deploy. El contexto durable vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- Se hace determinista la navegación rápida entre destinos de DISCADMIN: la navegación más reciente siempre conserva autoridad sobre el workspace y la URL.
- Una navegación nueva cancela inmediatamente cualquier fragment fetch dinámico anterior y vuelve obsoletas las esperas de scripts pendientes de Media, Releases o Blog.
- Si un módulo dinámico viejo termina de cargar después de haber cambiado de destino, ya no puede montar su contenido ni reescribir `?module=`.
- Events / Content Core y System Status participan en el mismo mecanismo de cancelación sin cambiar ONE SHELL / ONE SIDEBAR / ONE SESSION / ONE CENTRAL WORKSPACE.
- No hay cambios de API, base de datos ni contratos de publicación.

## Archivos modificados en este deploy

- `discadmin/admin-information-architecture.js` — coordina cancelación, dependencias dinámicas y tokens de navegación para que solo el destino vigente pueda completar.
- `tests/e2e/discadmin-information-architecture.spec.mjs` — fuerza una carrera Releases → Media con la dependencia de Releases retrasada y exige que Media permanezca como destino final.
- `README.md` — snapshot operativo de este deploy.

## Validación

- La rama debe pasar sintaxis JavaScript y la regresión dirigida de Information Architecture antes del merge.
- El PR debe pasar `BRVTAL CI / validate`, `PHP 8.5 Compatibility / php85` y el gate de README.
- Chromium debe demostrar que una navegación a Releases que sigue esperando su script no puede reaparecer después de que Media ya sea el destino vigente.
- Después del merge se verificará el CI completo del SHA exacto de `main` y el deploy exacto en Hostinger.
- CI verde implica **VALIDATED IN CODE**; observar el SHA exacto en Hostinger implica **DEPLOYED**.

## Qué sigue

1. Continuar simplificando DISCADMIN solo ante fricciones reproducibles del shell/workspace.
2. Mantener #122–#125 abiertos hasta ejecutar sus workflows autenticados y obtener evidencia real de producción.
3. No extender Media hacia CONNECTED mientras Media no tenga relaciones estructuradas públicas propias.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
