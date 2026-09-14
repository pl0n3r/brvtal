# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. Se reemplaza en cada deploy. El contexto durable vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- `Production Performance` ahora comprueba conectividad con producción antes de instalar Node, dependencias o Chromium.
- Si un runner de GitHub no puede alcanzar Hostinger, el workflow falla rápido en lugar de esperar cerca de seis minutos.
- La espera del marcador exacto del deploy quedó acotada a 8 intentos, con cache-busting explícito para evitar leer HTML viejo desde CDN.
- El navegador de Playwright queda cacheado entre runs para evitar descargar cientos de MB innecesariamente.

## Archivos modificados en este deploy

- `.github/workflows/production-performance.yml` — fail-fast de conectividad, espera acotada y caché de Chromium.
- `README.md` — snapshot operativo de este deploy.

## Validación

- El PR debe pasar `README Deploy Snapshot · PR / verify`, `BRVTAL CI / validate` y `PHP 8.5 Compatibility / php85` antes del merge.
- El workflow conserva la trazabilidad al SHA exacto de `main` y sigue exigiendo el marcador `?v=<short-sha>` antes de medir.
- Producción canónica: `https://www.brvtal.com.co`.

## Qué sigue

1. Confirmar en el próximo run automático que un problema de conectividad falla rápido y que un deploy accesible evita reinstalar Chromium cuando existe caché.
2. Resolver los issues abiertos validados, priorizando inconsistencias de contenido y relaciones del CMS.
3. Repetir la medición de rendimiento solo después de confirmar el SHA exacto en producción.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Especificaciones profundas: `docs/`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
