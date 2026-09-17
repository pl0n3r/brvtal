# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí, además del snapshot exacto de lo que cambió, un **panorama general actualizado de lo que sigue pendiente por hacer/desarrollar**. No debe limitarse únicamente al siguiente paso inmediato.

## Qué se hizo

- #421 elimina del Home los indicadores técnicos públicos `LIVE / CMS CONNECTED`, `STATIC / API OFFLINE` y el fallback técnico visible.
- Se retiran los contadores genéricos `xx / 07` incrustados en las secciones; el Hero estático ya no finge un total. El Hero Slider conserva su contador porque deriva del número real de slides configurados.
- Se conserva el indicador central de escena del header (`CORE /// 01`, etc.) y su posición centrada, porque pertenece a la navegación/atmósfera del sitio y no al contador visual de cada sección.
- Se introduce un sistema público compartido de botones/CTAs con 48 px de target, tipografía monoespaciada legible, bordes duros, estados primary/secondary/utility, hover/focus y reduced-motion.
- Se añade una escala de legibilidad compartida: microtexto funcional relevante sube a ~11–12 px o más y los headings extremos se reducen para conservar impacto sin dominar/cortar el viewport.
- Home, Contact y páginas canónicas de entidades cargan el mismo sistema visual de controles/legibilidad.
- Se amplía Playwright para cubrir ausencia de status/contadores genéricos, contador real del Hero Slider, targets táctiles, tamaños funcionales y overflow móvil.
- `AGENTS.md` incorpora como contrato durable que **cada deploy README debe mantener un panorama general de trabajo pendiente**, además del snapshot exacto del deploy.

## Archivos modificados en este deploy

- `AGENTS.md` — hace obligatorio y durable el panorama general pendiente en cada README de deploy.
- `README.md` — snapshot exacto del deploy + panorama general pendiente actualizado.
- `config/public_contact_page.php` — carga el sistema compartido de controles y legibilidad en Contact.
- `css/public-controls.css` — sistema global de CTAs/botones públicos y targets de interacción.
- `css/public-legibility.css` — escala compartida de microtexto funcional y límites de display typography para Home, Contact y entidades.
- `index.html` — elimina status/fallback técnico visible y contadores genéricos de sección; conserva el scene indicator central y añade los estilos compartidos.
- `index.php` — carga el sistema compartido también en páginas canónicas de entidades y mantiene versionado de assets.
- `tests/e2e/public-quick-wins.spec.mjs` — regresiones de contadores/status, Hero real, botones, legibilidad y overflow móvil.

## Validación

- Base inicial: `main` `4619d30b3af3a7bfd29731a48cefc126482d311b`, con BRVTAL CI #701 verde.
- PR activo: #432 — `design: improve public legibility and control consistency`.
- La rama debe pasar BRVTAL CI, SonarQube/revisión automática aplicable y revisión de findings válidos antes del squash merge.
- CI verde significará **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Cerrar #432/#421 con CI verde, resolver findings válidos, squash merge y verificar BRVTAL CI del SHA exacto de `main`.
- Después, avanzar #422: diferenciación visual de secciones del Home, glitch coherente, Hero ambiental y composición de Events sin reintroducir scroll hijacking.
- Mantener #415 (Memories administrable) como siguiente bloque funcional visual de mayor profundidad.

## Panorama general pendiente

Este panorama debe mantenerse actualizado en **cada deploy** y resumir trabajo relevante todavía abierto, aunque no forme parte del deploy actual.

- **Frontend público / impacto visual:** #422 — diferenciar visualmente las secciones del Home, glitch coherente en títulos, Hero con movimiento ambiental controlado y composición de Events más fuerte.
- **Memories administrable:** #415 — convertir Memories en una galería curada desde DISCADMIN, seleccionando Media Library existente, título, orden, publicación y viewer público editorial; mobile debe evitar una única columna monótona.
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
- **Idioma:** #212 — futura experiencia ES/EN con español canónico; la especificación debe revisarse antes de implementar porque la decisión más reciente es **conservar SOUND** y dejar el contenido sonoro para una fase posterior.
- **Analytics:** continuar madurez de GTM/GA4 y eventos `brvtal_*` sin reintroducir carga directa de GA4 fuera de GTM; Dashboard GA4 queda como evolución posterior cuando exista integración de lectura segura.
- **Hero Slider:** mejoras incrementales solo cuando aporten edición real: publicación programada, timeline más potente y/o drag-and-drop si siguen siendo necesidades válidas.
- **Performance / recovery:** conservar evidencia de Production Performance, arquitectura adaptativa y recovery rehearsal; optimizar solo cuando haya evidencia medida de regresión o cuello de botella.
