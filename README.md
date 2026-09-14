# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. Se reemplaza en cada deploy. El contexto durable vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- Se conecta el Archive público con **CONNECTED** usando únicamente relaciones ya servidas por la API.
- Un registro histórico que tenga lineup y/o sets relacionados muestra `EXPLORE CONNECTIONS ↗`; un registro sin relaciones no muestra ese CTA.
- La ruta conserva los query params actuales de discovery, añade `network_type=events` + `network_id=<event>` y salta a `#network`, por lo que reutiliza el estado compartible desplegado en el ciclo anterior.
- `OPEN RECORD ↗` permanece separado y sigue apuntando a la página canónica del Event.
- No se añade modelo de datos, endpoint, recomendador ni relación duplicada en frontend.

## Archivos modificados en este deploy

- `js/archive.js` — genera la ruta Archive → CONNECTED solo para eventos con relaciones públicas reales.
- `tests/e2e/public-archive.spec.mjs` — valida CTA, parámetros de red/hash y ausencia del CTA cuando no hay relaciones.
- `README.md` — snapshot operativo de este deploy.

## Validación

- El PR debe pasar `BRVTAL CI / validate`, `PHP 8.5 Compatibility / php85` y el gate de README antes del merge.
- Chromium debe validar que el Event histórico relacionado conserva `OPEN RECORD`, recibe `EXPLORE CONNECTIONS` con `network_type=events`, `network_id` correcto y `#network`, y que un Event sin relaciones no recibe ese enlace.
- Después del merge se verificará el CI completo del SHA exacto de `main` y el deploy exacto en Hostinger.
- CI verde implica **VALIDATED IN CODE**; observar el SHA exacto en Hostinger implica **DEPLOYED**.

## Qué sigue

1. Continuar cerrando rutas de discovery únicamente cuando una relación estructurada pública ya exista y aporte navegación real.
2. Mantener performance/recovery como mantenimiento medido, no como trabajo repetitivo sin evidencia.
3. Mantener pendientes #122–#125 hasta ejecutar sus workflows autenticados y obtener evidencia real de producción.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.