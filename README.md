# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo del deploy más reciente**. Se reemplaza en cada deploy; no acumula historial, arquitectura general ni checklists antiguos. El contexto durable del proyecto vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- PageSpeed identificó el logo oficial de BRVTAL como elemento LCP y mostró alrededor de 350 ms de `Resource load delay` antes de iniciar su descarga.
- El Home ahora declara un `preload` de imagen con `fetchpriority="high"` para `assets/brvtal-logo.jpeg` antes de `css/style.css`, de modo que el navegador puede solicitar el LCP antes de completar el descubrimiento normal del body.
- El preload y el `<img>` principal pasan por el mismo versionado de deploy (`?v=<sha>`), evitando una segunda URL de caché o una descarga duplicada.
- No se recomprimió ni reemplazó el logo, Genesis ni ningún asset histórico; este cambio no modifica calidad visual, contenido ni base de datos.

## Archivos modificados en este deploy

- `index.php` — inserta el preload del logo LCP antes del stylesheet crítico y conserva el versionado de deploy.
- `tests/project-operations-contract.php` — protege orden, prioridad y reutilización de la misma URL versionada entre preload e imagen.
- `README.md` — snapshot operativo de este deploy.

## Validación

- El PR debe pasar `README Deploy Snapshot / verify`, `BRVTAL CI / validate` y `PHP 8.5 Compatibility / php85` antes del merge.
- CI verde significa **VALIDATED IN CODE**; no equivale por sí solo a **VALIDATED IN PRODUCTION**.
- Producción canónica: `https://www.brvtal.com.co`.

## Qué sigue

1. Tras el deploy, repetir PageSpeed/Lighthouse sobre `https://www.brvtal.com.co` y comparar específicamente `Resource load delay`, `Resource load duration` y `Element render delay` del LCP.
2. Si el retraso de descarga baja pero el `Element render delay` sigue alto, revisar la fase de render/animación del Hero antes de recomprimir imágenes.
3. Solo si `Improve image delivery` sigue siendo material después de esta medición, optimizar `assets/brvtal-logo.jpeg` y Genesis con comparación visual previa, manteniendo los originales.

## Contexto durable

- Bootstrap canónico para nuevas sesiones: `AGENTS.md`.
- Especificaciones profundas: `docs/`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.

> Regla: este archivo describe **solo el deploy actual**. En el siguiente deploy se reemplaza completo con los archivos modificados, un resumen de lo realizado y el siguiente trabajo accionable.
