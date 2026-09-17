# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. Por decisión de producto vigente incluye también un panorama compacto de pendientes para no reconstruir prioridades en el siguiente ciclo. El contexto durable sigue en `AGENTS.md`, `docs/` y GitHub Issues.

## Qué se hizo

- Se implementa **#420** para eliminar el secuestro del scroll vertical en Events y hacer el runtime público más seguro al redimensionar la ventana.
- Events deja de depender del túnel horizontal con `pin`: el pin legacy se desmonta en todos los viewports y el track pasa a navegación horizontal nativa.
- El wheel/trackpad vertical vuelve a continuar por el Home; el contenido horizontal conserva swipe/touch, scroll horizontal real, teclado y drag con mouse.
- El snap horizontal cambia a `proximity` para que la navegación sea menos rígida.
- En desktop las cards de Events se reducen para mostrar más contexto simultáneamente; en mobile conservan tamaño táctil sin bloquear el scroll vertical.
- El coordinador público de scroll sincroniza Lenis y ScrollTrigger tras `resize`/cambio de orientación, con debounce por animation frame y sin refrescar mientras existe un scroll lock activo.
- Se añaden regresiones Playwright para pin removal, wheel vertical, drag horizontal, overflow y resize desktop → narrow → desktop sin quedar bloqueado.

## Archivos modificados en este deploy

- `README.md` — 🟡 MOD · snapshot exacto del deploy + panorama compacto de pendientes.
- `css/mobile-events.css` — 🟡 MOD · Events nativo en todos los viewports, snap suave y cards más contenidas.
- `js/menu-scroll-lock.js` — 🟡 MOD · sincronización resize-safe de Lenis/ScrollTrigger.
- `js/mobile-events.js` — 🟡 MOD · elimina el pin de Events globalmente y añade navegación nativa/drag/teclado.
- `tests/e2e/public-mobile-events.spec.mjs` — 🟡 MOD · cobertura de scroll vertical, drag horizontal y eliminación del pin.
- `tests/e2e/public-scroll-resize.spec.mjs` — 🟢 NEW · regresión de resize y continuidad del scroll.

## Validación

- Base exacta: `main` `4cbb1c62a8f94f411367f5e754c9ebd7d2b5376a`.
- Esa base corresponde al merge de **#424 / #419** y tiene **BRVTAL CI #677** verde.
- #419 quedó **VALIDATED IN CODE**; no se declara validación real de producción desde CI.
- Este cambio no toca API, base de datos, migrations, DISCADMIN ni el modelo de contenido.
- Pendiente en este head: **BRVTAL CI / validate**, SonarQube Cloud y CodeRabbit antes de squash merge.
- CI verde significará **VALIDATED IN CODE**. La comprobación real de resize/scroll en producción queda separada.

## Panorama general de lo pendiente

### P0 — bugs funcionales públicos
1. **#419 Menú mobile / header** — mergeado en `main`; pendiente validación real posterior en producción/dispositivo.
2. **#420 Resize / scroll / Events** — implementado en este deploy; pendiente CI/review/merge.

### P1 — quick wins globales
3. **#421 Legibilidad y sistema UI** — quitar `LIVE / CMS CONNECTED`, retirar contadores de escena fuera del Hero, aumentar microtexto funcional, reducir headings excesivos y normalizar botones/CTAs.
4. Revisar footer y Contact con la misma escala tipográfica y contraste funcional.

### P2 — dirección visual
5. **#422 Home visual** — diferenciar secciones con skins/texturas/profundidad, glitch controlado en títulos, Hero ambiental y Events con cards coherentes; sin reintroducir scroll hijacking ni motion pesado en mobile.

### P3 — Memories
6. **#415 Memories administrable** — Media Library = almacenamiento; Memories = curaduría.
7. Añadir `MEDIA → Memories` en DISCADMIN para seleccionar media existente, título, orden y publicación sin duplicar/borrar el asset original.
8. Public Memories: grid editorial/asimétrico, mobile 2 columnas con ritmo y viewer inmersivo PREV/NEXT/cierre.
9. No reutilizar ciegamente el enfoque relation-first del PR cerrado `#417`; quedó superseded por el modelo de galería curada.

### P4 — backlog posterior
10. Soundscape BRVTAL para **SOUND**, siempre opt-in.
11. Continuar simplificación de DISCADMIN sobre fricciones concretas; después Hero Slider, SEO centralizado, backups programados/off-site y demás backlog según prioridad/evidencia.
12. Smokes autenticados de producción solo cuando exista acceso autorizado; CI no sustituye validación real.

## Qué sigue

1. Llevar **#420** a PR, dejar BRVTAL CI, SonarQube y CodeRabbit verdes, squash merge y verificar el SHA exacto de `main`.
2. Continuar inmediatamente con **#421** — legibilidad, contadores, estado CMS y sistema global de botones.
3. Después ejecutar **#422** — evolución visual de Home — antes de entrar al nuevo modelo de Memories **#415**.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Visión pública: `#398`.
- Memories curado: `#415`.
- Quick wins: `#419`, `#420`, `#421`, `#422`.
- Testing/validación: `docs/TESTING.md`.
- GitHub Issues es la fuente de verdad para cada tarea individual y su estado.
