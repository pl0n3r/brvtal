# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo del deploy más reciente**. Se reemplaza en cada deploy; no acumula historial, arquitectura general ni checklists antiguos. El contexto durable del proyecto vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- Se respondió al hallazgo medido de Pingdom que calificó con `F` la compresión HTTP.
- `.htaccess` habilita compresión DEFLATE para HTML, texto, CSS, JavaScript, JSON, XML, XHTML y SVG cuando `mod_deflate` está disponible en LiteSpeed/Apache.
- Se excluyen de forma intencional JPEG, WOFF2 y otros binarios ya comprimidos para evitar trabajo inútil del servidor.
- Se añadió una cobertura contractual que protege la configuración de compresión y evita regresiones hacia recomprimir JPEG/WOFF2.
- No se modificó diseño, contenido, imágenes, base de datos ni comportamiento de DISCADMIN.

## Archivos modificados en este deploy

- `.htaccess` — activa compresión HTTP para respuestas textuales compatibles.
- `tests/project-operations-contract.php` — valida el contrato de compresión del servidor web.
- `README.md` — snapshot operativo de este deploy.

## Validación

- El deploy debe pasar `BRVTAL CI / validate`, `PHP 8.5 Compatibility / php85` y `README Deploy Snapshot / verify` antes del merge.
- CI verde significa **VALIDATED IN CODE**; no equivale por sí solo a **VALIDATED IN PRODUCTION**.
- Producción canónica: `https://www.brvtal.com.co`.

## Qué sigue

1. Repetir Pingdom contra `https://www.brvtal.com.co` y comprobar que la recomendación de compresión deja de ser `F`.
2. Confirmar en producción `Content-Encoding: gzip` o `br` en HTML/CSS/JS/JSON textuales.
3. Con esa evidencia, atacar el siguiente hallazgo medido: requests/Expires/redirects, sin aumentar complejidad ni degradar el contenido visual.

## Contexto durable

- Bootstrap canónico para nuevas sesiones: `AGENTS.md`.
- Especificaciones profundas: `docs/`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.

> Regla: este archivo describe **solo el deploy actual**. En el siguiente deploy se reemplaza completo con los archivos modificados, un resumen de lo realizado y el siguiente trabajo accionable.
