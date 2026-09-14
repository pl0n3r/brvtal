# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. Se reemplaza en cada deploy. El contexto durable vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- Se extiende el estado URL de discovery para que **CONNECTED** pueda restaurar y compartir la capa y entidad seleccionadas mediante `network_type` y `network_id`.
- Artists, Events, Sets y Releases usan el mismo mecanismo existente de Archive/Media; no se crea un router ni un estado paralelo.
- Back/Forward restaura el recorrido del grafo y una URL compartida vuelve a abrir la relación pública seleccionada después de cargar los datos.
- Los parámetros inválidos o incompletos no fuerzan una selección y el estado por defecto continúa siendo la primera ruta pública de Artists.
- La medición moderna anterior quedó saludable, por lo que performance y recovery pasan a mantenimiento continuo en `AGENTS.md` y discovery queda como prioridad activa; no se introduce otra optimización de imágenes sin evidencia.

## Archivos modificados en este deploy

- `js/public-discovery-url-state.js` — añade estado compartible/restaurable para CONNECTED.
- `tests/e2e/public-discovery-url-state.spec.mjs` — cubre URL inicial, convivencia con Archive/Media y Back/Forward del grafo.
- `AGENTS.md` — registra CONNECTED compartible, evidencia moderna de performance y avanza las prioridades durables sin repetir trabajo ya cerrado.
- `README.md` — snapshot operativo de este deploy.

## Validación

- El PR debe pasar `BRVTAL CI / validate`, `PHP 8.5 Compatibility / php85` y el gate de README antes del merge.
- Chromium debe validar que `network_type`/`network_id` restauran la selección, se mantienen al cambiar otros filtros y respetan el historial del navegador.
- `Project Operations` debe permanecer verde tras el ajuste de `AGENTS.md`, conservando sus invariantes de bootstrap y arquitectura.
- Después del merge se verificará el CI completo del SHA exacto de `main` y el deploy exacto en Hostinger.
- CI verde implica **VALIDATED IN CODE**; observar el SHA en Hostinger implica **DEPLOYED**, no validación autenticada de DISCADMIN.

## Qué sigue

1. Continuar discovery solo donde existan relaciones públicas reales, evitando duplicar datos de relación en frontend.
2. Mantener la performance pública bajo observación, pero no recomprimir assets mientras el baseline moderno siga saludable y no haya evidencia visual/técnica que lo justifique.
3. Mantener pendientes #122–#125 hasta ejecutar sus workflows autenticados y obtener evidencia real de producción.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.