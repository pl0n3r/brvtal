# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es el **snapshot operativo del deploy actual** y, por decisión de producto vigente, incluye además un **panorama general de trabajo pendiente** para que el siguiente ciclo pueda arrancar sin reconstruir prioridades. El contexto durable sigue viviendo en `AGENTS.md`, `docs/` y GitHub Issues.

## Qué se hizo

- Se prepara el repositorio para aprovechar mejor **GitHub Copilot / custom agents** sin cambiar el runtime público, DISCADMIN, API ni base de datos.
- Se añade una instrucción global de repositorio que obliga a Copilot a leer primero `AGENTS.md` y respetar arquitectura, seguridad, CI, mobile, accesibilidad, rendimiento y lenguaje de validación.
- Se añaden cuatro agentes especializados para delegar trabajo repetitivo sin mezclar responsabilidades:
  - **BRVTAL Implementer** — implementación enfocada de issues bien definidos;
  - **BRVTAL Test Specialist** — cobertura PHP/MariaDB/Playwright/WebKit;
  - **BRVTAL UX and Accessibility** — responsive, mobile, accesibilidad, motion y regresiones de scroll/layout;
  - **BRVTAL CI Fixer** — diagnóstico de BRVTAL CI y findings válidos de review/CodeRabbit.
- Se corrige el contrato del agente implementador para que el snapshot README liste exactamente el **diff actual del PR**, no solo los archivos de la tarea original.
- Los agentes no tienen autorización para ejecutar SQL destructivo, restores, despliegues manuales ni declarar producción validada a partir de CI.
- Se mantiene `AGENTS.md` como fuente canónica para evitar duplicar arquitectura o crear una segunda fuente de verdad.

## Archivos modificados en este deploy

- `.github/copilot-instructions.md` — 🟢 NEW · reglas globales para GitHub Copilot en BRVTAL.
- `.github/agents/brvtal-implementer.md` — 🟢 NEW · agente de implementación enfocada y contrato README alineado al diff real del PR.
- `.github/agents/brvtal-test-specialist.md` — 🟢 NEW · agente especializado en regresiones y testing.
- `.github/agents/brvtal-ux-accessibility.md` — 🟢 NEW · agente especializado en UX responsive/accesibilidad.
- `.github/agents/brvtal-ci-fixer.md` — 🟢 NEW · agente especializado en CI/review fixes.
- `README.md` — 🟡 MOD · snapshot operativo y panorama general de pendientes.

## Validación

- Base exacta: `main` `c262a8af5d1df0e520bdb1ad4d297df9cf206fa6`.
- Esa base estaba verde en **BRVTAL CI #655**.
- El primer head de este PR pasó **BRVTAL CI #667**.
- CodeRabbit detectó un finding válido sobre el contrato del README; ya fue corregido en la misma rama.
- No hay cambios de PHP, JavaScript productivo, CSS productivo, schema, migrations ni APIs.
- Pendiente: nueva ejecución de **BRVTAL CI / validate** y re-review del head actualizado antes de squash merge.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** a partir de CI.

## Panorama general de lo pendiente

### P0 — bugs funcionales / primero

1. **Menú mobile no abre**: corregir apertura/cierre, touch, foco, scroll lock y navegación; cubrir con Playwright mobile.
2. **Resize rompe el scroll**: eliminar estado roto después de cambiar tamaño/viewport; recalcular o retirar lógica frágil de Lenis/ScrollTrigger/pins; cubrir resize desktop↔mobile sin reload.
3. **Events secuestra el scroll vertical**: retirar wheel vertical→horizontal/pinning; conservar navegación horizontal natural por drag/touch/trackpad y reducir tamaño de cards.

### P1 — quick wins visuales y de legibilidad

4. Quitar el indicador público **LIVE / CMS CONNECTED**.
5. Quitar contadores de escena tipo `03 / 07` fuera del hero; en hero mostrar solo el contador real de banners/slides cuando aplique.
6. Corregir el header: un solo `MENU`, icono separado, targets táctiles claros y alineación correcta con **SOUND**.
7. **Mantener SOUND** como control preparado para una futura experiencia sonora; el contenido de audio se implementará después.
8. Subir la legibilidad global: footer, Contact, metadata, labels, botones y microtexto; reducir la brecha entre headings gigantes y texto funcional diminuto.
9. Definir un **Button Design System** uniforme para CTAs, secundarios, utilitarios e icon buttons en todo el sitio.
10. Reducir headings/composiciones excesivamente grandes donde se cortan o dominan demasiado el viewport.

### P2 — dirección visual de alto impacto

11. Dar a cada sección del Home una **identidad visual propia** mediante skins, texturas, fotografía, profundidad y motion controlado, manteniendo una sola identidad BRVTAL.
12. Añadir **glitch consistente a los títulos principales** (Events, Roster, Sets, Memories, Contact, etc.) con rojo BRVTAL como base y verde/otros acentos solo cuando tenga sentido.
13. Hacer el hero menos estático con **glitch ambiental sutil**, barridos/interferencia y parallax muy ligero; versión más simple para mobile/reduced motion.
14. Rehacer composición de **Events** como cards coherentes y autosuficientes: imagen + fecha/ciudad + estado + nombre + descripción + CTA; desktop mostrando aproximadamente 2–2.5 cards.
15. Mantener el impacto visual sin volver a introducir scroll hijacking, efectos pesados en coarse-pointer ni animaciones que afecten legibilidad.

### P3 — Memories / producto

16. Replantear **Memories** como una **galería curada administrable**, no como un feed directo de toda Media Library.
17. Añadir destino `MEDIA → Memories` dentro del shell único de DISCADMIN.
18. Desde Memories poder seleccionar media existente (imagen/video/audio), definir título, orden y estado de publicación; quitar de Memories sin borrar el archivo original.
19. Public Memories debe usar una grilla editorial/asimétrica; en mobile evitar una sola columna y usar 2 columnas con piezas destacadas de ancho completo cuando convenga.
20. Viewer inmersivo con imagen/video/audio, título, cierre y navegación anterior/siguiente.
21. Revisar cualquier implementación/PR relation-first anterior y reencauzarla para que no contradiga este modelo curado.

### P4 — después de la revisión pública

22. Añadir el **soundscape BRVTAL** al control SOUND en una fase separada, siempre opt-in y con volumen/motion responsables.
23. Continuar la simplificación/estabilización de DISCADMIN cuando existan fricciones concretas.
24. Seguir con mejoras incrementales del Hero Slider solo si aportan valor real de edición: scheduling, timeline/ordering más rico, etc.
25. Ejecutar los smoke tests de producción autenticados cuando exista acceso/entorno autorizado; CI por sí solo no cuenta como validación en producción.
26. Mantener performance, recovery, privacidad/analytics y seguridad según evidencia/uso real, evitando trabajo especulativo.

## Orden de ejecución inmediato

1. Terminar y squash-mergear este PR de tooling con CI/review verdes.
2. Convertir los P0/P1 anteriores en issues pequeños y no duplicados.
3. Resolver primero **menú mobile + resize/scroll**.
4. Seguir con **CMS status + contadores + header + tipografía/botones**.
5. Después **Events + dirección visual/glitch/hero**.
6. Finalmente abordar **Memories administrable** como cambio de producto más grande.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Visión pública: `#398`.
- Estrategia de validación: `docs/TESTING.md`.
- GitHub Issues sigue siendo la fuente de verdad para tareas individuales y su estado.
