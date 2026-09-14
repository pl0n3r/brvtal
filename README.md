# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. Se reemplaza en cada deploy. El contexto durable vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- Se corrige una inconsistencia visible en el Dashboard de DISCADMIN: la tarjeta técnica de PHP ya no muestra el literal obsoleto `8.3+`.
- El shell renderiza ahora la versión real del proceso PHP mediante `PHP_VERSION`, por lo que el dato seguirá al runtime efectivo de Hostinger sin requerir cambios manuales.
- La sustitución se realiza en el wrapper canónico `discadmin/index.php`, sin crear un segundo shell ni alterar la navegación o las sesiones.
- Se amplía la regresión del shell para exigir que la tarjeta PHP derive del runtime del servidor y no de una versión fija.

## Archivos modificados en este deploy

- `discadmin/index.php` — sustituye la tarjeta PHP del Dashboard por la versión real del runtime antes de entregar el shell.
- `tests/e2e/discadmin-admin-shell.spec.mjs` — protege que el runtime se derive de `PHP_VERSION` y conserva las regresiones de navegación móvil del shell.
- `README.md` — snapshot operativo de este deploy.

## Validación

- El PR debe pasar `BRVTAL CI / validate`, `PHP 8.5 Compatibility / php85` y el gate de README antes del merge.
- Como el cambio afecta `discadmin/*`, BRVTAL CI activa las validaciones pertinentes de shell/browser/real-stack según su planner.
- La prueba impide reemplazar el runtime dinámico por un valor fijo en el wrapper canónico.
- CI verde implica **VALIDATED IN CODE**; la verificación del SHA exacto desplegado se hará después del merge antes de marcar **DEPLOYED**.

## Qué sigue

1. Verificar el SHA exacto de `main` después del squash merge y esperar la confirmación de deploy de Hostinger.
2. Mantener pendientes #122–#125 hasta ejecutar sus workflows autenticados y obtener evidencia real de producción.
3. Continuar con simplificación/estabilización de DISCADMIN únicamente sobre fricción concreta y no sobre módulos ya cubiertos, como la navegación inicial de Media Library.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.