# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. Se reemplaza en cada deploy. El contexto durable vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- `Next Experience` deja de publicar datos fijos de Genesis y se alimenta del evento público activo del CMS.
- La selección prioriza un evento marcado `featured`; si no existe, usa el próximo evento activo por fecha.
- Fecha, hora, ciudad, venue, portada, título, navegación y CTA se renderizan en servidor desde el evento seleccionado.
- El CTA usa la ruta canónica `/events/<slug>`.
- Si no existe un evento elegible o la consulta falla, la portada muestra un fallback neutro (`NEXT SIGNAL`, fecha/hora/ubicación TBA) sin reutilizar datos ni arte de Genesis.
- Se añade cobertura contractual para selección, escape HTML, ruta, portada y fallback.

## Archivos modificados en este deploy

- `config/public_home.php` — selección y render SSR de `Next Experience` desde Events públicos.
- `index.php` — integra el evento dinámico antes de las transformaciones de assets del Home.
- `tests/public-home-contract.php` — regresión del Home dinámico y fallback seguro.
- `scripts/php85-compatibility.sh` — incorpora el nuevo contrato a la puerta PHP 8.5.
- `README.md` — snapshot operativo de este deploy.

## Validación

- El PR debe pasar `BRVTAL CI / validate` y `PHP 8.5 Compatibility / php85` antes del merge.
- La selección reutiliza el allowlist canónico de estados públicos de Events y no expone drafts.
- Sin cambios de esquema, SQL de producción ni mutaciones de contenido.
- Producción canónica: `https://www.brvtal.com.co`.
- La validación real de producción se hará después del deploy; CI por sí solo no la sustituye.

## Qué sigue

1. Confirmar en producción que `Next Experience` refleja el evento CMS seleccionado y cerrar #126 si coincide.
2. Validar de forma autenticada en DISCADMIN los fixes #123, #124 y #125.
3. Reproducir #122 con sesión recién autenticada antes de clasificarlo como defecto de Pages.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Especificaciones profundas: `docs/`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
