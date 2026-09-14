# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. Se reemplaza en cada deploy. El contexto durable vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- Se cierra la navegación bidireccional entre páginas canónicas y **CONNECTED**.
- Las páginas públicas de Artist, Event, Set y Release muestran `EXPLORE CONNECTIONS ↗`, apuntando a su misma entidad dentro del grafo mediante `network_type`, `network_id` y `#network`.
- Blog y CMS Pages permanecen fuera porque no son capas de CONNECTED; no se inventan relaciones ni destinos inexistentes.
- Los enlaces externos existentes (tickets, plataformas, redes) permanecen sin cambios y el nuevo CTA interno reutiliza el estado URL ya desplegado.
- Se añade cobertura del allowlist de cuatro capas y de IDs inválidos.

## Archivos modificados en este deploy

- `config/public_page.php` — añade el helper de ruta CONNECTED y el CTA interno para las cuatro entidades del grafo.
- `tests/public-entity-contract.php` — valida las cuatro rutas permitidas y rechaza Blog/Pages/IDs inválidos.
- `README.md` — snapshot operativo de este deploy.

## Validación

- El PR debe pasar `BRVTAL CI / validate`, `PHP 8.5 Compatibility / php85` y el gate de README antes del merge.
- `Public Entity contract` debe validar URLs exactas para Artists, Events, Sets y Releases y asegurar que Blog/Pages no reclamen una capa del grafo.
- Después del merge se verificará el CI completo del SHA exacto de `main` y el deploy exacto en Hostinger.
- CI verde implica **VALIDATED IN CODE**; observar el SHA exacto en Hostinger implica **DEPLOYED**.

## Qué sigue

1. No extender Media hacia CONNECTED mientras Media no tenga relaciones estructuradas públicas propias.
2. Pasar a la siguiente fricción concreta de DISCADMIN si no aparece otra ruta de discovery respaldada por relaciones reales.
3. Mantener #122–#125 abiertos hasta ejecutar sus workflows autenticados y obtener evidencia real de producción.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
