# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo del deploy más reciente**. Se reemplaza en cada deploy; no acumula historial, arquitectura general ni checklists antiguos. El contexto durable del proyecto vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- El preload del logo LCP introducido en el deploy anterior se mantiene intacto y sigue adelantando la descarga del logo oficial de BRVTAL.
- El Home ahora añade además un estilo crítico mínimo antes de `css/style.css` que mantiene `.hero-logo-wrap` visible desde el primer render con `opacity:1!important`.
- Esto evita que la animación de entrada de escritorio pueda ocultar temporalmente el elemento LCP mediante una opacidad inline, sin eliminar sus transformaciones visuales de escala/rotación ni el resto del motion stack.
- El helper de visibilidad es idempotente y no añade una nueva petición de red: el CSS crítico viaja inline en el HTML.
- No se recomprimieron imágenes, no se modificó contenido editorial y no hubo cambios de base de datos.

## Archivos modificados en este deploy

- `config/public_assets.php` — añade el helper crítico e idempotente que mantiene visible el wrapper del LCP antes del stylesheet principal.
- `index.php` — aplica el helper de visibilidad inmediatamente después del preload del LCP.
- `tests/project-operations-contract.php` — protege orden temprano, `opacity:1!important`, idempotencia y coexistencia con el preload versionado.
- `README.md` — snapshot operativo de este deploy.

## Validación

- El PR debe pasar `README Deploy Snapshot / verify`, `BRVTAL CI / validate` y `PHP 8.5 Compatibility / php85` antes del merge.
- CI verde significa **VALIDATED IN CODE**; no equivale por sí solo a **VALIDATED IN PRODUCTION**.
- Producción canónica: `https://www.brvtal.com.co`.

## Qué sigue

1. Tras el deploy, repetir PageSpeed/Lighthouse sobre `https://www.brvtal.com.co` y comparar `Resource load delay`, `Resource load duration` y `Element render delay` del logo LCP contra la medición anterior.
2. Si el render delay sigue siendo material, revisar el loader de arranque y cualquier otra capa que pueda cubrir el Hero antes de tocar calidad de imagen.
3. Optimizar `assets/brvtal-logo.jpeg` y Genesis solo si `Improve image delivery` continúa siendo un cuello de botella relevante y con comparación visual previa.

## Contexto durable

- Bootstrap canónico para nuevas sesiones: `AGENTS.md`.
- Especificaciones profundas: `docs/`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.

> Regla: este archivo describe **solo el deploy actual**. En el siguiente deploy se reemplaza completo con los archivos modificados, un resumen de lo realizado y el siguiente trabajo accionable.
