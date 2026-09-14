# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo del deploy más reciente**. Se reemplaza en cada deploy; no acumula historial, arquitectura general ni checklists antiguos. El contexto durable del proyecto vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- Cada JPEG/PNG nuevo subido por Media Library genera además un WebP `display` que conserva la proporción, con ancho máximo de 1920 px; el archivo original permanece intacto y autoritativo.
- El mapa público de Media Engine expone esa variante WebP de forma allowlisted junto a `square`, `card`, `hero` y variantes preserve-aspect existentes.
- El runtime público revisa cualquier `<img>` local de `/uploads/`, incluso imágenes insertadas dinámicamente o diferidas con `data-src`, y usa el WebP más adecuado para su contexto.
- Hero Slider usa contexto `hero`; CONNECTED usa `square`; Media usa `card`; imágenes genéricas conservan proporción; el visor prioriza la variante preserve-aspect de mayor tamaño.
- Si una variante WebP falla, el navegador vuelve automáticamente al original. Los originales WebP ya permanecen WebP y los GIF no se convierten de forma destructiva.
- No se modificaron base de datos, contenido editorial ni originales almacenados.

## Archivos modificados en este deploy

- `config/media.php` — genera el WebP preserve-aspect `display` para nuevos JPEG/PNG sin reemplazar el original.
- `api/public-media-delivery.php` — permite exponer de forma segura la variante `display`.
- `js/public-media.js` — aplica WebP globalmente a imágenes `/uploads/`, incluidos Hero Slider y nodos dinámicos, con fallback al original.
- `tests/media-library-contract.php` — protege generación, allowlist, contextos WebP y fallback.
- `README.md` — snapshot operativo de este deploy.

## Validación

- El PR debe pasar `README Deploy Snapshot / verify`, `BRVTAL CI / validate` y `PHP 8.5 Compatibility / php85` antes del merge.
- CI verde significa **VALIDATED IN CODE**; no equivale por sí solo a **VALIDATED IN PRODUCTION**.
- Producción canónica: `https://www.brvtal.com.co`.

## Qué sigue

1. Verificar en producción que una imagen subida/regenerada por Media Library se entregue como `--display-*.webp`, `--hero-*.webp`, `--card-*.webp` o `--square-*.webp` según contexto, manteniendo el original como fallback.
2. Repetir PageSpeed/Lighthouse y comprobar la reducción del bloque `Improve image delivery` para imágenes de `/uploads/`.
3. Tratar por separado los assets estáticos históricos como `assets/brvtal-logo.jpeg` y Genesis; no recomprimirlos sin comparación visual.
4. Si sigue siendo relevante después de imágenes/caché, revisar el retraso de render del LCP y el CLS móvil del Hero.

## Contexto durable

- Bootstrap canónico para nuevas sesiones: `AGENTS.md`.
- Especificaciones profundas: `docs/`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.

> Regla: este archivo describe **solo el deploy actual**. En el siguiente deploy se reemplaza completo con los archivos modificados, un resumen de lo realizado y el siguiente trabajo accionable.
