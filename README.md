# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo del deploy más reciente**. Se reemplaza en cada deploy. El contexto durable vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- Se añadieron derivados WebP medidos para el logo BRVTAL y el arte de Genesis, manteniendo los JPEG originales como fallback.
- La home sirve candidatos responsivos de 640 px y 886 px para reducir transferencia sin cambiar el diseño ni el contenido.
- La selección se basó en una matriz de compresión y revisión visual de los candidatos antes de integrarlos.
- Se amplió la cobertura contractual de assets públicos y se documentó la decisión operativa en `AGENTS.md`.

## Archivos modificados en este deploy

- `AGENTS.md` — registra la optimización estática medida.
- `README.md` — snapshot operativo de este deploy.
- `assets/brvtal-logo-640.webp` — derivado responsivo del logo.
- `assets/brvtal-logo-886.webp` — derivado WebP de resolución completa del logo.
- `assets/flyers/genesis-640.webp` — derivado responsivo del arte Genesis.
- `assets/flyers/genesis-886.webp` — derivado WebP de resolución completa del arte Genesis.
- `config/public_assets.php` — entrega los nuevos WebP con fallback JPEG y versionado existente.
- `index.html` — referencia los candidatos responsivos donde aplica.
- `tests/project-operations-contract.php` — verifica la nueva entrega de imágenes.

## Validación

- El PR debe pasar `README Deploy Snapshot · PR / verify`, `BRVTAL CI / validate` y `PHP 8.5 Compatibility / php85` antes del merge.
- La evidencia de generación mostró reducciones sustanciales frente a los JPEG fuente; por ejemplo, logo 640 px ~90 KB vs ~259 KB y Genesis 640 px ~88 KB vs ~350 KB.
- Después del merge, el SHA exacto de `main` debe volver a pasar los gates principales.
- Producción canónica: `https://www.brvtal.com.co`.

## Qué sigue

1. Confirmar el deploy del SHA exacto en producción.
2. Repetir la medición de rendimiento móvil/escritorio y comparar bytes transferidos y LCP con la evidencia anterior.
3. Solo si la mejora está confirmada, continuar con el siguiente cuello de botella medido.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Especificaciones profundas: `docs/`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
