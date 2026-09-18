# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí el snapshot exacto de lo que cambió y un panorama general actualizado de lo pendiente.

## Qué se hizo

- Corrige un finding válido de CodeRabbit detectado después del merge de #474.
- Mantiene un lock de RESET LOG fuera del DOM mientras el borrado está en curso.
- Si System Status se vuelve a renderizar por REFRESH o auto-refresh durante el reset, los controles de log recién creados siguen bloqueados.
- Un segundo intento de reset se ignora mientras el primero siga activo, evitando dos operaciones destructivas concurrentes.
- Al finalizar el reset, el lock se libera y los controles vuelven a su estado normal.
- Añade regresión Playwright que fuerza un rerender con el POST de reset detenido y confirma que no se emite un segundo borrado.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del follow-up de #474.
- `discadmin/system-status-v2.js` — lock persistente de reset a través de rerenders.
- `tests/e2e/discadmin-system-status-reset-log.spec.mjs` — regresión de carrera reset/rerender.

## Validación

- Base exacta: `main` `cb15117e86834dbaf4626d1369e5e45ef5592430`.
- Ese SHA exacto pasó BRVTAL CI #892 y queda **VALIDATED IN CODE**.
- El head del PR debe pasar BRVTAL CI, SonarCloud y CodeRabbit antes del squash merge.
- Tras el merge se verificará BRVTAL CI sobre el SHA exacto resultante de `main`.
- CI verde significa **VALIDATED IN CODE**, no validación de producción.
- No se ejecuta ningún RESET LOG real de producción durante esta validación.

## Qué sigue

- Cerrar este follow-up de #474 y resolver el review thread de CodeRabbit.
- Continuar #475 / #365: Dashboard V2 autoritativo para Health/Activity.
- Continuar #191: integridad referencial de `theme.active`.
- Después avanzar #237 y mantener #451 como burn-down de calidad revalidado.

## Panorama general pendiente

- **Sonar / calidad:** #451 continúa por riesgo y área con cambios pequeños.
- **Dashboard / DISCADMIN:** #365, #348 y #351.
- **Settings / Theme:** #191 — integridad referencial de `theme.active`.
- **Apariencia:** #149.
- **Hero Slider:** #237 y #221.
- **Seguridad editorial / navegación:** #257, #216, #174 y #193.
- **SEO editorial:** #182, #214, #204 y #272 antes de #390.
- **Content / edición:** #224 y #252.
- **Activity / operaciones:** #232 y #195.
- **Bulk Actions:** #275.
- **Archivo cultural:** #398 / #403.
- **Memories:** #415 / PR #434 requiere reconciliación; ninguna migración de producción automática.
- **Idioma:** #212 por fases.
- **Backups:** #389 con autorización externa.
- **Completados recientemente:** #207, #391, #260 y #388 están integrados en código; este deploy corrige el finding post-merge de #388.
