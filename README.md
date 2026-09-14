# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo del deploy más reciente**. Se reemplaza en cada deploy; no acumula historial, arquitectura general ni checklists antiguos. El contexto durable del proyecto vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- Se corrigió el arranque del workflow `Production Performance`: el primer run falló antes de medir porque `actions/setup-node` con `cache: npm` exige un lockfile y este repositorio no usa `package-lock.json`.
- El workflow ahora replica el patrón ya validado por BRVTAL CI: Node 24, caché explícito de `~/.npm` basado en `package.json` y `npm install --prefer-offline --no-audit --no-fund`.
- La lógica de medición, el target canónico `https://www.brvtal.com.co/`, la verificación del marcador `?v=<short-sha>` y la evidencia móvil/escritorio permanecen sin cambios.
- No se modificó UI, contenido, base de datos ni calidad de imágenes.

## Archivos modificados en este deploy

- `.github/workflows/production-performance.yml` — corrige instalación/caché de dependencias sin requerir un lockfile inexistente.
- `README.md` — snapshot operativo de este deploy.

## Validación

- El PR debe pasar `README Deploy Snapshot / verify`, `BRVTAL CI / validate` y `PHP 8.5 Compatibility / php85` antes del merge.
- Después del merge, el SHA exacto de `main` debe volver a pasar ambos gates.
- El siguiente `Production Performance` exitoso debe observar primero el SHA exacto en producción y luego medir FCP/LCP/CLS y el desglose del LCP.
- Producción canónica: `https://www.brvtal.com.co`.

## Qué sigue

1. Confirmar que `Production Performance` complete la medición móvil y escritorio sobre el SHA exacto desplegado.
2. Comparar `resource load delay`, `resource load duration` y `element render delay` con la evidencia anterior de PageSpeed.
3. Solo con esa evidencia decidir si el siguiente cuello de botella está en render inicial o en transferencia de imágenes.

## Contexto durable

- Bootstrap canónico para nuevas sesiones: `AGENTS.md`.
- Especificaciones profundas: `docs/`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.

> Regla: este archivo describe **solo el deploy actual**. En el siguiente deploy se reemplaza completo con los archivos modificados, un resumen de lo realizado y el siguiente trabajo accionable.
