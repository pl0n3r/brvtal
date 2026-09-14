# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo del deploy más reciente**. Se reemplaza en cada deploy; no acumula historial, arquitectura general ni checklists antiguos. El contexto durable del proyecto vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- Se corrigió el versionado de los assets de CONNECTED/Related Content que todavía podían cargarse sin `?v=<deploy-sha>`.
- `archive.js` ahora toma su propio parámetro `v` del script ya versionado y lo reutiliza tanto para `related-content.js` como para `related-content.css`.
- El CSS se inserta antes del import dinámico con el marcador que ya entiende `related-content.js`, evitando una segunda hoja de estilos.
- Se añadió cobertura contractual para impedir que vuelva el import literal sin versión.
- No se cambió la lógica del grafo, las relaciones públicas ni el diseño de CONNECTED.

## Archivos modificados en este deploy

- `js/archive.js` — propaga el SHA del deploy al módulo y stylesheet de Related Content.
- `README.md` — snapshot operativo de este deploy.
- `tests/related-content-contract.php` — protege el versionado del JS/CSS y prohíbe el import dinámico sin versión.

## Validación

- El deploy debe pasar `BRVTAL CI / validate`, `PHP 8.5 Compatibility / php85` y `README Deploy Snapshot / verify` antes del merge.
- CI verde significa **VALIDATED IN CODE**; no equivale por sí solo a **VALIDATED IN PRODUCTION**.
- Producción canónica: `https://www.brvtal.com.co`.

## Qué sigue

1. Repetir el waterfall/PageSpeed de `https://www.brvtal.com.co` y confirmar que `archive.js`, `related-content.js` y `related-content.css` comparten el mismo `?v=<deploy-sha>`.
2. Confirmar en producción el redirect canónico `brvtal.com.co/*` → `https://www.brvtal.com.co/*`.
3. Continuar con el siguiente cuello medido de imágenes, priorizando Genesis/logo y variantes responsive sin degradar calidad visual.

## Contexto durable

- Bootstrap canónico para nuevas sesiones: `AGENTS.md`.
- Especificaciones profundas: `docs/`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.

> Regla: este archivo describe **solo el deploy actual**. En el siguiente deploy se reemplaza completo con los archivos modificados, un resumen de lo realizado y el siguiente trabajo accionable.
