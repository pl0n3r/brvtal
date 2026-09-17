# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Snapshot exacto del deploy actual.

## Archivos modificados

- `README.md` — snapshot exacto del deploy.
- `css/mobile-events.css` — Events usa scroll horizontal nativo, snap suave y cards más contenidas.
- `js/menu-scroll-lock.js` — resincroniza Lenis y ScrollTrigger de forma segura después de resize/orientation change.
- `js/mobile-events.js` — elimina el pin legacy de Events y conserva navegación horizontal por drag, touch y teclado.
- `tests/e2e/public-mobile-events.spec.mjs` — cubre scroll vertical, drag horizontal, accesibilidad y eliminación del pin.
- `tests/e2e/public-scroll-resize.spec.mjs` — cubre resize desktop → narrow → desktop sin dejar el scroll bloqueado.
