# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- El visor fullscreen de Media ahora inmoviliza el documento subyacente mientras está abierto.
- El lock público se amplió a un modelo con propietarios, de modo que MENU y Media viewer comparten la misma coordinación de scroll/Lenis sin desbloquearse entre sí.
- Solo el primer modal activo congela la página y solo el último propietario que cierre restaura posición, estilos y Lenis.
- `public-media.js` adquiere el lock al abrir una imagen y lo libera al cerrar por botón, backdrop o Escape.
- La posición X/Y se conserva exactamente; en desktop enhanced Lenis se pausa/reanuda y en touch/reduced-motion el bloqueo funciona sin Lenis.
- Se conserva la accesibilidad ya existente del viewer: `role=dialog`, `aria-modal`, foco inicial, Escape, flechas, focus trap y devolución de foco.

## Archivos modificados en este deploy

- `js/menu-scroll-lock.js` — generaliza el lock a propietarios públicos (`menu`, `media-viewer`) con restauración solo al liberar el último.
- `js/public-media.js` — conecta el ciclo de vida del viewer al lock compartido.
- `tests/e2e/public-media-viewer.spec.mjs` — regresión browser para wheel, Escape, foco, posición exacta, Lenis y ownership simultáneo.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `f0e1ec584b3d2926f6663c2adef22d14cc612000`.
- Ese SHA exacto tenía BRVTAL CI completo, PHP 8.5 Compatibility y Backup Recovery Rehearsal en success.
- No hay cambios de esquema, migraciones ni mutaciones de datos de producción.
- La corrección reutiliza la infraestructura de scroll lock ya validada por #238 en vez de duplicar otra implementación para Media.
- Pendiente de **BRVTAL CI / validate** y **PHP 8.5 Compatibility / php85** del PR.
- Tras el merge se verificará la matriz completa sobre el SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir en esta misma rama cualquier fallo detectado.
2. Con CI verde, hacer squash merge.
3. Verificar BRVTAL CI completo, PHP 8.5 y Backup Recovery sobre el SHA exacto resultante de `main`.
4. Cerrar #242 y continuar con el siguiente issue público prioritario que siga vigente.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
