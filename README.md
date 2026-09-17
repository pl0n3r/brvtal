# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

## Qué se hizo

- Se elimina el pin legacy que convertía el scroll vertical de Events en un túnel horizontal.
- Events conserva navegación horizontal nativa por drag, touch, teclado y scroll horizontal real.
- Se resincronizan Lenis y ScrollTrigger después de resize/orientation change para evitar que el scroll quede roto hasta recargar.
- Se añaden regresiones Playwright para wheel vertical, drag horizontal, overflow y resize desktop → narrow → desktop.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy.
- `css/mobile-events.css` — Events usa scroll horizontal nativo, snap suave y cards más contenidas.
- `js/menu-scroll-lock.js` — resincroniza Lenis y ScrollTrigger de forma segura después de resize/orientation change.
- `js/mobile-events.js` — elimina el pin legacy de Events y conserva navegación horizontal por drag, touch y teclado.
- `tests/e2e/public-mobile-events.spec.mjs` — cubre scroll vertical, drag horizontal, accesibilidad y eliminación del pin.
- `tests/e2e/public-scroll-resize.spec.mjs` — cubre resize desktop → narrow → desktop sin dejar el scroll bloqueado.

## Validación

- Base: `main` `4cbb1c62a8f94f411367f5e754c9ebd7d2b5376a`.
- BRVTAL CI y revisión automatizada deben quedar verdes antes del squash merge.
- CI verde significa **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Cerrar findings válidos de review, obtener CI verde, hacer squash merge y verificar el CI del SHA exacto de `main`.
