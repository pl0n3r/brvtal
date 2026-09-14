# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo del deploy más reciente**. Se reemplaza en cada deploy; no acumula historial, arquitectura general ni checklists antiguos. El contexto durable del proyecto vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- El HAR de producción confirmó que el deploy anterior está activo en Hostinger, PHP 8.5 sirve el Home y Brotli comprime HTML/CSS/JavaScript correctamente.
- Se alineó la política de caché de JavaScript first-party con CSS/imágenes: un año + `immutable` para archivos `.js` versionados por deploy.
- Se añadió cobertura explícita para el MIME `application/x-javascript` que LiteSpeed/Hostinger está entregando en producción.
- Se actualizó el contrato para impedir regresiones en compresión y caché de JavaScript.
- `AGENTS.md` dejó de marcar como pendiente el versionado de `related-content.js/css`, porque ya está implementado, y ahora prioriza mediciones con navegador moderno.
- No se modificaron diseño, contenido, base de datos ni comportamiento funcional de DISCADMIN.

## Archivos modificados en este deploy

- `.htaccess` — añade expiración anual e `immutable` para JavaScript y cubre `application/x-javascript` en compresión.
- `tests/project-operations-contract.php` — protege la política de compresión/caché de JavaScript.
- `AGENTS.md` — actualiza el estado durable y las prioridades de rendimiento.
- `README.md` — snapshot operativo de este deploy.

## Validación

- El PR debe pasar `README Deploy Snapshot / verify`, `BRVTAL CI / validate` y `PHP 8.5 Compatibility / php85` antes del merge.
- CI verde significa **VALIDATED IN CODE**; no equivale por sí solo a **VALIDATED IN PRODUCTION**.
- La evidencia HAR previa sí valida en producción el deploy anterior de compresión: `Content-Encoding: br` y `PHP/8.5.6` en `https://www.brvtal.com.co`.

## Qué sigue

1. Tras el deploy, verificar en producción que `/js/public-runtime-loader.js?v=<sha>` responde con `Cache-Control: public, max-age=31536000, immutable` y expiración anual.
2. Repetir Lighthouse/PageSpeed con Chrome moderno sobre `https://www.brvtal.com.co` y usar ese waterfall para decidir el siguiente cambio.
3. Tratar las descargas masivas de imágenes de Pingdom/Chrome 61 como una limitación de ese navegador, que no soporta `loading="lazy"`; no degradar el frontend moderno para mejorar esa métrica heredada.

## Contexto durable

- Bootstrap canónico para nuevas sesiones: `AGENTS.md`.
- Especificaciones profundas: `docs/`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.

> Regla: este archivo describe **solo el deploy actual**. En el siguiente deploy se reemplaza completo con los archivos modificados, un resumen de lo realizado y el siguiente trabajo accionable.
