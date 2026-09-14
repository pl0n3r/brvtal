# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo del deploy más reciente**. Se reemplaza en cada deploy; no acumula historial, arquitectura general ni checklists antiguos. El contexto durable del proyecto vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- Se añadió una medición reproducible de rendimiento real contra `https://www.brvtal.com.co/` usando Chromium/Playwright, sin depender de PageSpeed o GTmetrix para obtener el desglose básico del LCP.
- El nuevo workflow `Production Performance` corre automáticamente después de un `BRVTAL CI` exitoso de `main` y también puede ejecutarse manualmente.
- Antes de medir tras un deploy, el workflow espera a que producción exponga `?v=<short-sha>` del commit exacto; así evita atribuir métricas a un deploy anterior.
- La medición registra móvil y escritorio: FCP, LCP, CLS, TTFB, `resource load delay`, `resource load duration`, `element render delay`, transferencia del recurso LCP y cantidad de requests.
- Los resultados quedan en el Job Summary y como JSON descargable por 14 días. No se cambió UI, contenido, base de datos ni calidad de imágenes.

## Archivos modificados en este deploy

- `.github/workflows/production-performance.yml` — ejecuta la medición post-deploy y manual, verifica el SHA desplegado y guarda evidencia.
- `tests/e2e/production-performance-probe.mjs` — mide Web Vitals y descompone el LCP directamente en Chromium.
- `tests/project-operations-contract.php` — protege URL canónica, trazabilidad del SHA, medición móvil/escritorio y métricas del LCP.
- `README.md` — snapshot operativo de este deploy.

## Validación

- El PR debe pasar `README Deploy Snapshot / verify`, `BRVTAL CI / validate` y `PHP 8.5 Compatibility / php85` antes del merge.
- Después del merge, el SHA exacto de `main` debe volver a pasar ambos gates.
- El primer `Production Performance` exitoso sobre ese SHA será evidencia de **DEPLOYED** y de medición real del Home; las métricas resultantes podrán usarse para decidir el siguiente ajuste.
- Producción canónica: `https://www.brvtal.com.co`.

## Qué sigue

1. Revisar el primer run automático de `Production Performance` y comparar el LCP móvil/escritorio con la evidencia anterior de PageSpeed, especialmente `resource load delay`, `resource load duration` y `element render delay`.
2. Si el render delay sigue siendo material, revisar el loader/capas de arranque antes de recomprimir imágenes.
3. Si el cuello de botella pasa a ser transferencia de imagen, optimizar el logo estático y Genesis con comparación visual previa, preservando originales.

## Contexto durable

- Bootstrap canónico para nuevas sesiones: `AGENTS.md`.
- Especificaciones profundas: `docs/`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.

> Regla: este archivo describe **solo el deploy actual**. En el siguiente deploy se reemplaza completo con los archivos modificados, un resumen de lo realizado y el siguiente trabajo accionable.
