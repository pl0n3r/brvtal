# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. Por decisión de producto vigente incluye también un panorama compacto de pendientes para no reconstruir prioridades en el siguiente ciclo. El contexto durable sigue en `AGENTS.md`, `docs/` y GitHub Issues.

## Qué se hizo

- Se implementa **#419** para endurecer el menú público en mobile/touch y limpiar la composición del header.
- El estado del menú pasa a derivarse de `aria-hidden` y el controlador accesible toma el toggle en capture phase, evitando que el booleano privado del listener legacy se desincronice al cerrar por Escape, navegación u otros controles.
- MENU mantiene `aria-controls`, `aria-expanded`, diálogo modal, focus trap, Escape y retorno de foco; además sincroniza `inert`, visibilidad, transform y pointer events en cada cambio de estado.
- Al navegar desde el panel, el menú se cierra y libera el scroll lock antes de continuar.
- Se elimina el texto duplicado visual del cursor sobre MENU dejando vacío su cursor-label dinámico, pero conservando el cursor gráfico y el botón funcional.
- SOUND se conserva sin autoplay y permanece como control explícito para el futuro soundscape BRVTAL.
- Header y controles reciben targets táctiles de 44 px, separación estable, icono `+ / ×` con caja propia y ajustes mobile para evitar solapes y overflow.
- El panel mobile admite scroll vertical, usa `100svh` como mínimo y reduce la escala de enlaces para que la navegación siga alcanzable en pantallas cortas.
- La regresión Playwright existente ahora cubre apertura/cierre repetido, scroll lock, Escape/foco, cierre por navegación, geometría SOUND/MENU y ausencia de overflow a 390 px.

## Archivos modificados en este deploy

- `css/input-accessibility.css` — 🟡 MOD · targets táctiles, geometría del header, icono MENU y layout/scroll del panel mobile.
- `js/menu-accessibility.js` — 🟡 MOD · controlador touch-safe basado en estado ARIA, foco, inert y cierre fiable.
- `tests/e2e/public-menu-accessibility.spec.mjs` — 🟡 MOD · cobertura desktop + mobile de interacción, scroll lock, foco, repetición y geometría.
- `README.md` — 🟡 MOD · snapshot exacto del deploy + panorama compacto de pendientes.

## Validación

- Base exacta: `main` `301820895bdcdef04886ce59596904b9f3c494c4`.
- Esa base tiene **BRVTAL CI #675** verde.
- El fix anterior de SonarQube Cloud quedó squash-merged en esa base y SonarQube reportó **Quality Gate passed**, sin volver a presentar el solapamiento source/test.
- Este cambio no toca API, base de datos, migrations, DISCADMIN ni el modelo de contenido.
- Pendiente en este head: **BRVTAL CI / validate** y CodeRabbit antes de squash merge.
- CI verde significará **VALIDATED IN CODE**. La validación real del menú en dispositivos/producción sigue separada y no se declarará automáticamente.

## Panorama general de lo pendiente

### P0 — bugs funcionales públicos
1. **#419 Menú mobile / header** — implementado en este deploy; pendiente CI/review/merge y validación real posterior.
2. **#420 Resize / scroll / Events** — resize puede romper el scroll y Events secuestra el wheel vertical; hacer el runtime resize-safe y devolver scroll vertical nativo.

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

1. Llevar #419 a PR, dejar **BRVTAL CI / validate** y CodeRabbit verdes, squash merge y verificar el SHA exacto de `main`.
2. Continuar inmediatamente con **#420** — resize/scroll y eliminación del scroll hijacking de Events.
3. Después ejecutar el batch global **#421** y la evolución visual **#422** antes de entrar al nuevo modelo de Memories #415.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Visión pública: `#398`.
- Memories curado: `#415`.
- Quick wins: `#419`, `#420`, `#421`, `#422`.
- Testing/validación: `docs/TESTING.md`.
- GitHub Issues es la fuente de verdad para cada tarea individual y su estado.
