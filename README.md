# BRVTAL — Último deploy

<p align="center">
  <a href="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml"><img alt="BRVTAL CI" src="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg?branch=main"></a>
  <a href="https://sonarcloud.io/dashboard?id=pl0n3r_brvtal"><img alt="Sonar Quality Gate" src="https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_brvtal&metric=alert_status"></a>
  <a href="https://github.com/pl0n3r/brvtal/actions/workflows/production-deploy-observer.yml"><img alt="Deploy Observer" src="https://github.com/pl0n3r/brvtal/actions/workflows/production-deploy-observer.yml/badge.svg?branch=main"></a>
</p>

> **Development dashboard** · snapshot profesional de **solo el deploy actual**.

## Progress convention

- ✅ ~~Struck through~~ = completed and verified through the required delivery gates.
- 🚧 Normal text = pending or currently in progress.

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Work line | 🚧 **#519 visual content ordering (v0.1.21)** | shared drag/touch/keyboard ordering for Artists · Releases · Sets · Blog |
| Base exacta | ✅ **VALIDATED IN CODE** | `main` `c4f962095e60ef2c61a3f8e798f2ad8949d3ba80` |
| Version | 🚧 **0.1.20 → 0.1.21** | patch deploy |
| Producción | 🚧 **NOT VALIDATED IN PRODUCTION** | bounded Hostinger observer still failed to expose the current release |

## Huella del cambio

<!-- brvtal:git-delta -->

| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **19** | **+000** | **−000** | **+000** |

## Calidad y entrega

<!-- brvtal:gate-plan -->

| Control | Estado / contrato |
| --- | --- |
| Gates | **preflight · fast[PHP+JS] · database · chromium · real-stack · webkit** |
| Ordering API | exact-set · transaction · row locks · contiguous normalization · rollback |
| Interaction | pointer/touch drag · keyboard arrows · visible focus · autosave |
| Partial views | search/filter disables reorder; no subset writes |
| Public order | Artists · Sets · Releases · Blog consume canonical `sort_order` first |
| Sonar + CodeRabbit | parallel on stable head |
| Exact-main | CI of resulting main SHA after squash merge |

## Flujo de entrega

```mermaid
flowchart LR
 A["Release v0.1.21"] --> U["Shared ordering primitive"]
 U --> API["Transactional reorder API"]
 API --> M["4 Admin modules"]
 M --> Q["CI / Sonar / CodeRabbit"]
 Q --> X["Squash + exact-main"]
```

## Qué se hizo

- Artists, Releases, Sets y Blog comparten un único componente visual de ordering.
- Drag usa Pointer Events y cubre mouse/touch; el handle también admite Arrow Up/Down.
- El reorder se guarda automáticamente y revierte el DOM al snapshot previo si el servidor falla.
- Búsquedas/filtros desactivan el reorder para impedir escribir colecciones parciales.
- El endpoint valida IDs positivos/únicos, exige el set completo actual, bloquea filas con `FOR UPDATE`, normaliza posiciones 0..N-1 y registra cambios en Admin Activity.
- Los formularios ya no exponen Sort Order numérico; editar conserva la posición y crear añade al final.
- El API público prioriza `sort_order` para Releases/Blog; Artists/Sets ya lo hacían.
- Versión **0.1.21**.

## Archivos modificados en este deploy

- `config/content_ordering.php`, `api/reorder.php` — contrato y persistencia compartida.
- `discadmin/content-ordering.js`, `discadmin/content-ordering.css` — interacción visual reutilizable.
- `discadmin/index-core.php`, `discadmin/admin-modules.js`, `discadmin/index.php` — integración Artists/Sets y assets.
- `discadmin/releases.js`, `discadmin/blog.js` — integración de módulos dinámicos.
- `api/index.php`, `api/releases.php`, `api/blog.php`, `api/public.php` — orden canónico en lecturas Admin/públicas.
- `tests/content-ordering-contract.php`, `tests/e2e/discadmin-content-ordering.spec.mjs` — integridad y UX.
- `AGENTS.md`, `README.md`, `config/version.php`, `package.json` — documentación/release.

## Validación

- Base exacta `c4f9620`: BRVTAL CI / `validate` success.
- Production Deploy Observer de esa base: failure externo; no se declara producción validada.
- Contrato PHP cubre whitelist, IDs, exact-set, CSRF, row lock y ausencia de inputs Sort Order.
- Playwright cubre teclado, Pointer Events tipo touch, rollback, filtros y sincronización de stores.
- CI, Sonar y CodeRabbit deben cerrar sobre el head estable antes del merge.

## Qué sigue

| Lane | Trabajo |
| --- | --- |
| **NOW** | 🚧 [#519](https://github.com/pl0n3r/brvtal/issues/519) · completar y validar visual ordering. |
| **NEXT** | 🚧 [#518](https://github.com/pl0n3r/brvtal/issues/518) · canonical professional Admin data grid. |
| **LATER** | 🚧 [#257](https://github.com/pl0n3r/brvtal/issues/257), [#525](https://github.com/pl0n3r/brvtal/issues/525) · general editor protection + rich Blog editor. |
| **EVIDENCE** | 🚧 [#564](https://github.com/pl0n3r/brvtal/issues/564) · gather more samples before Phase D. |
| **BLOCKED / EXTERNAL** | 🚧 [#534](https://github.com/pl0n3r/brvtal/issues/534) · Hostinger production freshness. |
