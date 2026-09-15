# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- CMS Pages rechaza `content_json` malformado o valores JSON escalares que el renderer público no puede consumir como estructura.
- Los drafts de Pages pueden conservar contenido vacío para no romper el flujo editorial incompleto.
- Una Page publicada debe usar locale `en`, coherente con la entrega pública English-only.
- La validación de locale también cubre `PUT` parciales combinando el estado existente bloqueado con el patch antes de actualizar.
- Las nuevas Pages usan `en` por defecto tanto en la API como en DISCADMIN; las Pages existentes conservan su locale al editarse.
- Se documenta como requisito de seguridad un WAF liviano Apache/LiteSpeed con reglas básicas y conservadoras de ModSecurity cuando Hostinger lo permita. No se activa ningún WAF en este deploy.

## Archivos modificados en este deploy

- `api/pages-contract.php` — helpers puros para validar JSON de Pages y el contrato de publicación English-only.
- `api/index.php` — aplica el contrato en POST/PUT, incluido el estado final de actualizaciones parciales.
- `discadmin/pages-publication-contract.js` — hace English-first el formulario de nuevas Pages sin alterar registros existentes.
- `discadmin/index.php` — carga la mejora modular de Pages dentro del shell canónico.
- `tests/api-contract.php` — cubre JSON válido/inválido, drafts, locale de publicación y wiring API/DISCADMIN.
- `docs/SECURITY-HARDENING.md` — registra el requisito del WAF LiteSpeed/ModSecurity, sus límites y la validación previa requerida.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Pendiente de los gates del PR: sintaxis/contratos de BRVTAL CI, PHP 8.5 Compatibility y los gates path-aware que correspondan.
- No hay migración de base de datos ni SQL de producción en este cambio.
- El requisito WAF es documentación únicamente; no modifica `.htaccess`, Hostinger ni reglas de producción.
- Tras el merge se verificará BRVTAL CI y PHP 8.5 Compatibility sobre el SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Cerrar #296 y #297 con CI verde y verificar el SHA exacto de `main` después del squash merge.
2. Mantener #206 separado: el renderer/builder visual de Pages no se rediseña en este deploy.
3. Evaluar soporte real de ModSecurity en Hostinger y falsos positivos antes de cualquier futura activación del WAF.
4. Continuar el backlog de integridad, incluida la solución atómica pendiente para #294.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Hardening adicional: `docs/SECURITY-HARDENING.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
