# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí el snapshot exacto de lo que cambió y un panorama general actualizado de lo pendiente.

## Qué se hizo

- Añade un relay permanente para las anotaciones de SonarQube Cloud.
- Cuando termina el check externo **SonarCloud Code Analysis**, GitHub Actions lee sus anotaciones con `checks: read`.
- El relay publica o actualiza un comentario estable en el PR con nivel, archivo, línea, título y mensaje.
- Esto evita depender del endpoint de annotations que el conector de GitHub de ChatGPT no puede abrir directamente.
- Incluye `workflow_dispatch` para poder relanzar manualmente el relay con un check run ID y PR concretos.
- Valida que el check manual pertenezca al PR indicado antes de publicar resultados.
- Pagina tanto annotations como comentarios existentes y serializa el upsert para evitar comentarios duplicados.
- Usa permisos mínimos (`checks: read`, `pull-requests: write`) y no hace checkout ni ejecuta código del PR.
- `AGENTS.md` documenta ese comentario como fuente canónica legible por el conector para findings Sonar detallados.

## Archivos modificados en este deploy

- `.github/workflows/sonar-annotation-relay.yml` — relay de anotaciones Sonar hacia comentarios de PR.
- `AGENTS.md` — contrato permanente para consumir el relay en revisiones.
- `README.md` — snapshot exacto de este cambio de tooling.

## Validación

- Base exacta: `main` `e652c6fa8f3cbbf75d8ec9682f68c86741cfd552`.
- Ese SHA exacto pasó BRVTAL CI #917 y queda **VALIDATED IN CODE**.
- El PR de este tooling debe pasar BRVTAL CI, SonarCloud y CodeRabbit antes del squash merge.
- Después del merge se verificará BRVTAL CI sobre el SHA exacto resultante de `main`.
- El relay se considerará funcional cuando un nuevo check Sonar publique su comentario con las anotaciones reales.
- CI verde significa **VALIDATED IN CODE**, no validación de producción.
- No hay cambios de runtime público, base de datos ni operaciones destructivas.

## Qué sigue

- Cerrar y validar el relay de Sonar.
- Re-sincronizar #475 / #365 contra el nuevo `main` y verificar que el próximo Sonar check deje detalle legible en el PR.
- Continuar #191 y #237 como líneas independientes.
- Mantener #451 como burn-down de calidad con findings concretos, no inferidos desde conteos agregados.

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
- **Completados recientemente:** #207, #391, #260 y #388 están integrados en código.
