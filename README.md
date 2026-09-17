# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. Por decisión de producto vigente incluye también un panorama compacto de pendientes para no reconstruir prioridades en el siguiente ciclo. El contexto durable sigue en `AGENTS.md`, `docs/` y GitHub Issues.

## Qué se hizo

- Se prepara el repositorio para aprovechar **GitHub Copilot / custom agents** sin cambiar runtime público, DISCADMIN, API ni base de datos.
- Se añade `.github/copilot-instructions.md`, obligando a leer `AGENTS.md` y respetar arquitectura, seguridad, CI, mobile, accesibilidad, rendimiento y lenguaje de validación.
- Se añaden cuatro agentes especializados: **Implementer**, **Test Specialist**, **UX and Accessibility** y **CI Fixer**.
- Se corrige el contrato del Implementer: `README.md` debe listar exactamente el diff actual del PR y refrescarse si cambia antes del merge.
- Los agentes no pueden ejecutar SQL destructivo, restores, despliegues manuales ni declarar producción validada a partir de CI.

## Archivos modificados en este deploy

- `.github/copilot-instructions.md` — 🟢 NEW · reglas globales para GitHub Copilot.
- `.github/agents/brvtal-implementer.md` — 🟢 NEW · implementación enfocada + contrato README/diff.
- `.github/agents/brvtal-test-specialist.md` — 🟢 NEW · regresiones y testing.
- `.github/agents/brvtal-ux-accessibility.md` — 🟢 NEW · UX responsive, mobile, motion y accesibilidad.
- `.github/agents/brvtal-ci-fixer.md` — 🟢 NEW · CI y findings válidos de review.
- `README.md` — 🟡 MOD · snapshot del deploy + panorama compacto de pendientes.

## Validación

- Base: `main` `c262a8af5d1df0e520bdb1ad4d297df9cf206fa6`; **BRVTAL CI #655** verde.
- Primer head del PR: **BRVTAL CI #667** verde.
- CodeRabbit detectó un finding válido sobre el contrato README; fue corregido y el thread quedó resuelto.
- **BRVTAL CI #669** detectó que este snapshot perdió el encabezado contractual `## Qué sigue`; este commit lo corrige sin debilitar el test.
- No hay cambios de PHP, JavaScript/CSS productivo, schema, migrations ni APIs.
- Pendiente: nuevo **BRVTAL CI / validate** y re-review del head actualizado.
- CI verde = **VALIDATED IN CODE**, no **VALIDATED IN PRODUCTION**.

## Panorama general de lo pendiente

### P0 — bugs funcionales
1. **Menú mobile**: no abre. Corregir tap/click, apertura/cierre, foco, Escape, scroll lock y navegación; Playwright mobile.
2. **Resize / scroll**: cambiar el viewport rompe el scroll hasta reload. Hacer desktop↔mobile resiliente y eliminar estados frágiles de Lenis/ScrollTrigger/pins.
3. **Events scroll**: retirar wheel vertical→horizontal y pinning. El scroll vertical siempre baja la página; horizontal solo por drag/touch/trackpad natural.

### P1 — quick wins de UI
4. Quitar `LIVE / CMS CONNECTED`.
5. Quitar contadores de escena `NN / 07` fuera del Hero; el Hero solo debe mostrar el contador real de banners cuando aplique.
6. Header: un solo `MENU`, icono separado, targets táctiles claros y alineación con **SOUND**. **SOUND se conserva** para audio futuro.
7. Subir legibilidad global: footer, Contact, metadata, labels, botones y microtexto; reducir headings que se cortan o dominan demasiado.
8. Definir un **Button Design System** consistente para primary, secondary, utility e icon buttons.

### P2 — impacto visual
9. Diferenciar cada sección del Home con skins/texturas/fotografía/profundidad/motion, manteniendo una sola identidad BRVTAL.
10. Glitch controlado en títulos principales; rojo BRVTAL como base y otros acentos solo cuando corresponda.
11. Hero con glitch ambiental sutil, barridos/interferencia y parallax ligero; versión simple para mobile/reduced motion.
12. Events: cards coherentes con imagen + fecha/ciudad + estado + nombre + descripción + CTA; desktop ~2–2.5 visibles, sin secuestrar scroll.

### P3 — Memories
13. **Media Library = almacenamiento; Memories = curaduría.** Issue canónica: `#415`.
14. Añadir `MEDIA → Memories` en DISCADMIN para seleccionar media existente (imagen/video/audio), título, orden y estado; quitar de Memories sin borrar el asset.
15. Public Memories: grid editorial/asimétrico; mobile 2 columnas con piezas destacadas de ancho completo; viewer inmersivo con PREV/NEXT/cierre.
16. No reutilizar ciegamente el enfoque relation-first de PR `#417`; quedó superseded.

### P4 — después
17. Añadir soundscape BRVTAL a **SOUND**, siempre opt-in.
18. Continuar simplificación de DISCADMIN sobre fricciones concretas; después Hero Slider, SEO/backups y otras features según prioridad/evidencia.
19. Ejecutar smokes autenticados de producción solo cuando exista acceso autorizado; CI no sustituye validación real.

## Qué sigue

1. Dejar este PR de tooling verde, resolver cualquier finding válido, squash merge y verificar CI del SHA exacto de `main`.
2. Crear issues pequeños/no duplicados para P0/P1.
3. Implementar primero **menú mobile**, después **resize/scroll + Events scroll**, y luego el batch visual rápido.
4. Seguir con dirección visual Home/Events y, finalmente, **Memories administrable #415**.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Visión pública: `#398`.
- Memories curado: `#415`.
- Testing/validación: `docs/TESTING.md`.
- GitHub Issues es la fuente de verdad para cada tarea individual y su estado.
