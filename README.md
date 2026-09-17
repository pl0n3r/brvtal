# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md`, `docs/` y los issues de producto.

## Qué se hizo

- Se prepara el repositorio para aprovechar mejor **GitHub Copilot / custom agents** sin cambiar el runtime público, DISCADMIN, API ni base de datos.
- Se añade una instrucción global de repositorio que obliga a Copilot a leer primero `AGENTS.md` y respetar arquitectura, seguridad, CI, mobile, accesibilidad, rendimiento y lenguaje de validación.
- Se añaden cuatro agentes especializados para delegar trabajo repetitivo sin mezclar responsabilidades:
  - **BRVTAL Implementer** — implementación enfocada de issues bien definidos;
  - **BRVTAL Test Specialist** — cobertura PHP/MariaDB/Playwright/WebKit;
  - **BRVTAL UX and Accessibility** — responsive, mobile, accesibilidad, motion y regresiones de scroll/layout;
  - **BRVTAL CI Fixer** — diagnóstico de BRVTAL CI y findings válidos de review/CodeRabbit.
- Los agentes no tienen autorización para ejecutar SQL destructivo, restores, despliegues manuales ni declarar producción validada a partir de CI.
- Se mantiene `AGENTS.md` como fuente canónica para evitar duplicar arquitectura o crear una segunda fuente de verdad.

## Archivos modificados en este deploy

- `.github/copilot-instructions.md` — 🟢 NEW · reglas globales para GitHub Copilot en BRVTAL.
- `.github/agents/brvtal-implementer.md` — 🟢 NEW · agente de implementación enfocada.
- `.github/agents/brvtal-test-specialist.md` — 🟢 NEW · agente especializado en regresiones y testing.
- `.github/agents/brvtal-ux-accessibility.md` — 🟢 NEW · agente especializado en UX responsive/accesibilidad.
- `.github/agents/brvtal-ci-fixer.md` — 🟢 NEW · agente especializado en CI/review fixes.
- `README.md` — 🟡 MOD · snapshot operativo de este deploy.

## Validación

- Base exacta: `main` `c262a8af5d1df0e520bdb1ad4d297df9cf206fa6`.
- Esa base está verde en **BRVTAL CI #655**.
- No hay cambios de PHP, JavaScript productivo, CSS productivo, schema, migrations ni APIs.
- La validez de los perfiles usa el formato oficial de custom agents de GitHub (`.github/agents/*.md` con YAML frontmatter) y las instrucciones de repositorio usan `.github/copilot-instructions.md`.
- Pendiente: **BRVTAL CI / validate** y review del PR antes de squash merge.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** porque este cambio no requiere ni demuestra validación real del sitio.

## Qué sigue

1. Merge de esta configuración solo con BRVTAL CI verde.
2. Convertir la revisión visual reciente en issues pequeños y priorizados para poder delegar los quick wins sin pisar el trabajo principal.
3. Atacar primero bugs funcionales rápidos: menú mobile, resize/scroll, limpieza del menú/header y eliminación de indicadores/contadores innecesarios.
4. Después continuar tipografía/botones/Events y la dirección visual de Home.
5. Replantear Memories como galería curada administrable desde Media Library, no como la propuesta relation-first anterior.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Visión pública: `#398`.
- Memories pendiente de re-scope: `#415`.
- Estrategia de validación: `docs/TESTING.md`.
