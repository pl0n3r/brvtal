# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí, además del snapshot exacto de lo que cambió, un **panorama general actualizado de lo que sigue pendiente por hacer/desarrollar**. No debe limitarse únicamente al siguiente paso inmediato.

## Qué se hizo

- Se inició #421 para unificar el sistema visual de controles públicos y mejorar la legibilidad general del frontend.
- Se añadió una primera capa global de botones/CTAs con alturas, tipografía, bordes, hover, focus y targets táctiles consistentes.
- El trabajo de este deploy continúa en curso; el README se volverá a refrescar antes del PR/merge para que la lista de archivos coincida exactamente con el alcance final.

## Archivos modificados en este deploy

- `css/public-controls.css` — nueva base compartida para CTAs, botones utilitarios, acciones de entidades, Contact, Archive, Memories y controles del Hero.
- `README.md` — snapshot del deploy y panorama general pendiente obligatorio.

## Validación

- Base inicial: `main` `4619d30b3af3a7bfd29731a48cefc126482d311b`.
- El trabajo actual todavía no ha completado su ciclo de tests/PR/CI.
- CI verde significará **VALIDATED IN CODE**, no validación de producción.

## Qué sigue en este deploy

- Completar #421: eliminar el status técnico público, retirar contadores de sección fuera del Hero, conservar únicamente el contador real del Hero Slider, mejorar microtexto/jerarquía tipográfica y terminar de integrar el sistema de botones.
- Añadir/ajustar cobertura Playwright y contratos aplicables.
- Abrir PR, resolver findings válidos, pasar BRVTAL CI, hacer squash merge y verificar CI del SHA exacto de `main`.

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
- **Analytics:** continuar madurez de GTM/GA4 y eventos `brvtal_*` sin reintroducir carga directa de GA4 fuera de GTM.
- **Hero Slider:** mejoras incrementales solo cuando aporten edición real: publicación programada, timeline más potente y/o drag-and-drop si siguen siendo necesidades válidas.
- **Performance / recovery:** conservar evidencia de Production Performance, arquitectura adaptativa y recovery rehearsal; optimizar solo cuando haya evidencia medida de regresión o cuello de botella.
