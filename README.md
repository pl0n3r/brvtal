# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. Se reemplaza en cada deploy. El contexto durable vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- Corrige la visualización de fechas de Events al volver a abrir registros guardados, normalizando el valor SQL al formato requerido por `datetime-local`.
- Al entrar directamente a Sets, precarga Artists y Events para que los selectores de relaciones no dependan de navegación previa.
- Las cargas de Settings/Media usadas por Hero Slider tienen un timeout explícito y pasan a estado de error en vez de quedar indefinidamente en `LOADING HERO MANAGER…`.
- El error de Hero Slider ofrece `RETRY` para reintentar sin abandonar DISCADMIN.

## Archivos modificados en este deploy

- `discadmin/admin-reliability.js` — correcciones de fecha, relaciones de Sets y timeout/retry del Hero Slider.
- `discadmin/index.php` — carga el parche de fiabilidad dentro del shell canónico antes del Hero Slider.
- `README.md` — snapshot operativo de este deploy.

## Validación

- El PR debe pasar `BRVTAL CI / validate` y `PHP 8.5 Compatibility / php85` antes del merge.
- Los cambios de `discadmin/` fuerzan Chromium y real-stack en el planner de CI.
- Sin cambios de esquema, SQL de producción ni contenido.
- Producción canónica: `https://www.brvtal.com.co/discadmin`.

## Qué sigue

1. Validar en producción los issues #123, #124 y #125 después del deploy.
2. Resolver #126 para que `Next Experience` deje de depender del bloque estático legado de Genesis.
3. Reproducir #122 con una sesión recién autenticada antes de clasificarlo como defecto de Pages.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Especificaciones profundas: `docs/`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
