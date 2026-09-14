# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. Se reemplaza en cada deploy. El contexto durable vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- Se añade una regresión autenticada para el issue #122 usando el stack real de CI: PHP 8.5 + MariaDB + sesión admin + CSRF.
- La prueba crea una CMS Page publicada con `content_json` válido (`{"text":"Manifiesto"}`), vuelve a consultarla por API y verifica que JSON, locale, status y metadata persistan.
- El smoke deja de usar una contraseña fallback dentro del spec y exige la credencial efímera que ya exporta el runner real-stack.
- No cambia comportamiento de producción, esquema, contenido ni datos reales.

## Archivos modificados en este deploy

- `tests/e2e/content-core-real-stack.spec.mjs` — cobertura autenticada de persistencia de Pages y credencial solo vía entorno.
- `README.md` — snapshot operativo de este deploy.

## Validación

- El PR debe pasar `BRVTAL CI / validate` y `PHP 8.5 Compatibility / php85` antes del merge.
- La regresión corre únicamente contra la MariaDB desechable del job `real-stack`; no toca producción.
- Si pasa, #122 queda validado en código como no reproducible en el stack actual con una sesión recién autenticada; seguirá pendiente únicamente de reproducción autenticada en producción.
- Producción canónica: `https://www.brvtal.com.co`.

## Qué sigue

1. Registrar el resultado de esta regresión en #122.
2. Validar de forma autenticada en producción los fixes ya integrados de #123, #124 y #125 antes de cerrar esos issues.
3. Mantener el workflow `Production Performance` corto; la última medición saludable completó en decenas de segundos con Chromium cacheado.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Especificaciones profundas: `docs/`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
