# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- CONNECTED conserva su semántica ARIA de tabs y ahora implementa el patrón de teclado correspondiente.
- Solo la pestaña seleccionada permanece en el orden normal de `Tab`; las demás usan roving `tabindex=-1`.
- `ArrowRight`/`ArrowDown` avanzan, `ArrowLeft`/`ArrowUp` retroceden y `Home`/`End` saltan al inicio/final, con wrap y activación automática del contenido.
- Cada tab se asocia mediante `aria-controls` con el panel de entidades, cuyo `aria-labelledby` sigue la capa seleccionada.
- Se añade foco visible explícito para navegación por teclado.
- La activación por teclado reutiliza el click existente, de modo que no crea una segunda ruta de estado y mantiene la integración con el URL state de Public Discovery.

## Archivos modificados en este deploy

- `js/related-content.js` — roving focus, navegación por flechas/Home/End y asociación tab/tabpanel para CONNECTED.
- `css/related-content.css` — foco visible de las tabs.
- `tests/e2e/public-related-content.spec.mjs` — regresión Playwright del patrón ARIA/teclado, wrap, foco y contenido activado.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Cambio preparado sobre `main` `1e87b5bc4c73c3260307ddc7141feac55fc076dd`, cuyo ciclo completo post-merge estaba verde antes de abrir esta rama.
- Revisión estática confirma que el comportamiento mouse/touch existente sigue usando el mismo listener de click.
- Cobertura E2E dirigida añadida; su ejecución automatizada queda a cargo de los gates del PR porque este flujo no usa Work ni dispone aquí de runner local.
- Pendiente de **BRVTAL CI / validate** y **PHP 8.5 Compatibility / php85** del PR.
- Tras el merge se verificará la matriz completa sobre el SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Abrir el PR y ejecutar Chromium junto con los gates path-aware.
2. Corregir en la misma rama cualquier regresión detectada.
3. Con CI verde, hacer squash merge y verificar la matriz completa sobre el SHA exacto de `main`.
4. Cerrar #293 con el merge y continuar con el siguiente bug público abierto, priorizando #291 si sigue vigente.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
