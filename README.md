# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo del deploy más reciente**. Se reemplaza en cada deploy; no acumula historial, arquitectura general ni checklists antiguos. El contexto durable del proyecto vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- Se simplificó el README para que funcione como handoff corto del deploy actual.
- Se dejó de usar como manual técnico acumulativo o tablero histórico de características.
- Se formalizó que cada PR destinada a `main` debe reemplazar este snapshot con el alcance real de ese deploy.
- Se añadió un gate de GitHub Actions que compara el diff real del PR contra la lista de archivos del README y falla si falta o sobra alguno.
- El detalle arquitectónico y las decisiones durables permanecen en `AGENTS.md`/`docs`, evitando duplicación y desactualización.

## Archivos modificados en este deploy

- `.github/workflows/readme-deploy-snapshot.yml` — valida que el README enumere exactamente los archivos del PR antes del merge.
- `AGENTS.md` — contrato operativo para mantener este README por deploy y refrescarlo si cambia el alcance.
- `README.md` — nuevo formato de snapshot del último deploy.
- `tests/project-operations-contract.php` — contrato que protege el formato compacto y evita volver al README acumulativo.

## Validación

- El deploy debe pasar `BRVTAL CI / validate`, `PHP 8.5 Compatibility / php85` y `README Deploy Snapshot / verify` antes del merge.
- CI verde significa **VALIDATED IN CODE**; no equivale por sí solo a **VALIDATED IN PRODUCTION**.
- Producción canónica: `https://www.brvtal.com.co`.

## Qué sigue

1. Corregir el versionado de `js/related-content.js` y `css/related-content.css` para que usen el mismo SHA del deploy que el resto del runtime público.
2. Repetir medición del waterfall sobre `https://www.brvtal.com.co` y confirmar el redirect canónico bare → `www` en producción.
3. Continuar optimización pública solo sobre cuellos de botella medidos, sin degradar la experiencia visual ni reintroducir dependencias pesadas en mobile.

## Contexto durable

- Bootstrap canónico para nuevas sesiones: `AGENTS.md`.
- Especificaciones profundas: `docs/`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.

> Regla: este archivo describe **solo el deploy actual**. En el siguiente deploy se reemplaza completo con los archivos modificados, un resumen de lo realizado y el siguiente trabajo accionable.
