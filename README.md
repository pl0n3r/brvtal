# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. Se reemplaza en cada deploy. El contexto durable vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- Se amplía la medición automática de producción para dejar evidencia de waterfall de recursos en Chromium moderno, además de FCP/LCP/CLS y el breakdown del LCP ya existentes.
- Cada medición conserva el orden real de solicitudes y registra duración, transferencia, tipo de iniciador, status, `Cache-Control`, `Content-Encoding` y `Content-Type` cuando están disponibles.
- El resumen de Actions muestra los recursos más lentos y pesados y cuantifica compresión de texto first-party y caching `immutable` de assets estáticos versionados.
- La instrumentación es observacional: no introduce nuevos thresholds ni modifica la entrega pública; sirve para que la siguiente optimización se base en evidencia real.
- Se añade una regresión sintética del analizador para proteger orden, ranking y clasificación de cache/compresión sin depender de la red.

## Archivos modificados en este deploy

- `tests/e2e/production-performance-probe.mjs` — incorpora metadata HTTP y waterfall al artefacto/summary de producción.
- `tests/e2e/production-performance-waterfall.mjs` — normaliza recursos y calcula slowest/heaviest y evidencia de cache/compresión.
- `tests/e2e/production-performance-waterfall.spec.mjs` — valida el análisis con recursos first-party y third-party sintéticos.
- `README.md` — snapshot operativo de este deploy.

## Validación

- El PR debe pasar `BRVTAL CI / validate`, `PHP 8.5 Compatibility / php85` y el gate de README antes del merge.
- La prueba dirigida debe validar orden del waterfall, ranking por duración/transferencia y conteos de `immutable`/compresión.
- Después del merge, `Production Performance` debe observar el SHA exacto desplegado en Hostinger y ejecutar mediciones móvil/escritorio con el nuevo waterfall.
- CI verde implica **VALIDATED IN CODE**; el workflow de producción confirma **DEPLOYED** cuando observa el SHA exacto, pero la evidencia de rendimiento debe interpretarse antes de hacer otra optimización.

## Qué sigue

1. Revisar el waterfall móvil y escritorio generado sobre el SHA exacto de `main` y localizar el siguiente cuello de botella medible.
2. Optimizar únicamente si la evidencia moderna muestra un recurso o fase dominante; no reaccionar a artefactos de navegadores legacy.
3. Mantener pendientes #122–#125 hasta ejecutar sus workflows autenticados y obtener evidencia real de producción.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.