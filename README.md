# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí, además del snapshot exacto de lo que cambió, un **panorama general actualizado de lo que sigue pendiente por hacer/desarrollar**. No debe limitarse únicamente al siguiente paso inmediato.

## Qué se hizo

- #422 intensifica la identidad visual del Home sin cambiar la arquitectura de navegación ni reintroducir scroll hijacking.
- Se definen skins diferenciadas para Hero, Manifesto, Events, Roster, Sets, Memories y cierre/Contact manteniendo negro/rojo como identidad madre; Genesis conserva su verde ácido contextual.
- Los títulos principales de Events, Roster, Sets, Memories y Contact reciben un glitch rojo breve e intermitente, no una animación constante.
- El Hero mantiene una interferencia ambiental ligera después de cargar mediante capas CSS no interactivas; en mobile/coarse pointer se simplifica y con `prefers-reduced-motion` se desactiva.
- Events adopta tarjetas más coherentes: artwork, metadata, status, título, descripción y CTA. En desktop se mantiene aproximadamente 2–2.5 tarjetas visibles con scroll horizontal nativo; mobile conserva desplazamiento vertical normal.
- Las tarjetas con ticket mantienen `TICKETS ↗`; las que no tienen una acción real reciben `EXPLORE EVENTS ↓` sin inventar URLs de eventos.
- El enhancer observa reemplazos dinámicos del track para conservar la CTA después de que el API renderiza Events.
- Contact reutiliza el mismo lenguaje de señal/glitch para cerrar la experiencia visual de forma consistente.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy + panorama general pendiente actualizado.
- `config/public_contact_page.php` — carga la nueva capa visual compartida en Contact.
- `css/public-visual-identity.css` — skins por sección, glitch controlado, Hero ambiental y composición visual de Events con fallbacks mobile/reduced-motion.
- `index.php` — carga/versiona la capa visual y el enhancer ligero de Events en el Home canónico.
- `js/public-home-visual.js` — garantiza una acción visible en tarjetas Events sin duplicar CTAs reales y soporta re-render dinámico.
- `tests/e2e/public-home-visual-identity.spec.mjs` — cobertura desktop/mobile/reduced-motion, CTAs, scroll nativo y ausencia de overflow global.

## Validación

- Base: `main` `40a24648226030dff19b1a323b0fd82e09cdfaaa`, con BRVTAL CI #713 verde (`fast`, MariaDB, Chromium, real-stack y `validate`).
- El cambio debe pasar BRVTAL CI, revisión automatizada aplicable y findings válidos antes del squash merge.
- CI verde significará **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Cerrar #422 mediante PR, resolver findings válidos, squash merge y verificar BRVTAL CI del SHA exacto de `main`.
- Hacer spot-check real en producción de desktop/mobile: Hero ambiental, legibilidad del glitch, Events nativo y Contact; solo después podrá marcarse **VALIDATED IN PRODUCTION**.
- Continuar con #415: Memories administrable/curada desde DISCADMIN como siguiente bloque visual-funcional de mayor profundidad.

## Panorama general pendiente

Este panorama debe mantenerse actualizado en **cada deploy** y resumir trabajo relevante todavía abierto, aunque no forme parte del deploy actual.

- **Memories administrable:** #415 — galería curada desde DISCADMIN usando Media Library existente, título/contexto, orden, publicación y viewer editorial; mobile debe conservar composición rica en lugar de una columna monótona.
- **Analytics / GA4:** #427 — completar el mapeo de eventos `brvtal_*` dentro de GTM/GA4, ampliar medición estructurada de Events, Artists, Sets, Memories y Contact, y dejar preparada una futura lectura segura de GA4 en Dashboard sin cargar GA4 directamente fuera de GTM.
- **Experiencia pública / estabilidad:** seguir cerrando defectos reales de navegación, scroll, resize, mobile y legibilidad sin reintroducir scroll hijacking ni dependencias pesadas innecesarias.
- **Event Record / archivo cultural:** #403 y visión #398 — profundizar relaciones reales Event ↔ Artist ↔ Set ↔ Release ↔ Memory sin inferencias falsas ni APIs paralelas.
- **SEO:** #390 — workspace SEO centralizado; #391 — corregir gutter/alineación del bloque SEO actual; #272 — enriquecer structured data por tipo de entidad.
- **DISCADMIN:** #348 — simplificar IA/navegación; #351 — rediseñar Theme Studio; #365 — eliminar duplicación de Dashboard V2 y preservar navegación exacta por registro.
- **Operación / historial:** #232 — paginación completa de Activity/Version History; #207 — evitar que System Status muestre capacidad del host como cuota primaria cuando fallen métricas administradas.
- **Media / mobile:** #260 — inspector de Media Library accesible inmediatamente después de seleccionar un asset en móvil.
- **Seguridad editorial / UX:** #257 — proteger cambios no guardados en editores legacy antes de Escape/Cancelar.
- **Backups:** #389 — scheduling seguro y copia opcional a Google Drive; requiere autorización/credenciales externas para conectar Drive en producción.
- **System Status:** #388 — acción RESET LOG dentro de diagnósticos con POST + CSRF + confirmación.
- **Bulk Actions:** #275 — permitir alcanzar registros más allá del recorte local de 500 sin hacer creer que la búsqueda es exhaustiva.
- **Idioma:** #212 — futura experiencia ES/EN con español canónico; revisar la especificación antes de implementar y conservar SOUND como control del producto.
- **Hero Slider:** mejoras incrementales solo cuando aporten edición real: publicación programada, timeline más potente y/o drag-and-drop si siguen siendo necesidades válidas.
- **Performance / recovery:** conservar evidencia de Production Performance, arquitectura adaptativa y recovery rehearsal; optimizar solo con evidencia medida de regresión o cuello de botella.
