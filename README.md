# BRVTAL — Último deploy

<p align="center">
  <a href="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml"><img alt="BRVTAL CI" src="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg?branch=main"></a>
  <a href="https://sonarcloud.io/dashboard?id=pl0n3r_brvtal"><img alt="Sonar Quality Gate" src="https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_brvtal&metric=alert_status"></a>
  <a href="https://github.com/pl0n3r/brvtal/actions/workflows/production-deploy-observer.yml"><img alt="Deploy Observer" src="https://github.com/pl0n3r/brvtal/actions/workflows/production-deploy-observer.yml/badge.svg?branch=main"></a>
</p>

> **Production incident #631** · snapshot de **solo el deploy actual**: v0.1.47 exact `main` `6bea701a2f69501c61713a3d0ce95cd50a399f53` tiene CI exact-main y Deploy Observer verdes. Smoke #35940837144 acreditó health/Home/Admin/dashboard/Events/date y agotó el watchdog en `sets-relations` con `failureOperation: null`; la auditoría detectó navegación previa sin bound y cleanup fuera del watchdog. **Engineering roles:** SRE / production incident responder, QA automation engineer.

## Progress convention

- ✅ ~~Struck through~~ = completed and verified through the required delivery gates.
- 🚧 Normal text = pending or currently in progress.

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Work line | 🚧 **#631 · bounded smoke cleanup** | `work/issue-631`; reserva `53201ee4-e329-438f-95fd-e89b7dcdbba5` |
| Base exacta | ✅ ~~main v0.1.47~~ | `6bea701a2f69501c61713a3d0ce95cd50a399f53` |
| Versión | ✅ ~~v0.1.47 sin incremento~~ | mantenimiento exclusivo de prueba/documentación |
| CI del SHA exacto de main | ✅ ~~success~~ | [#35940651923](https://github.com/pl0n3r/brvtal/actions/runs/35940651923) |
| Deploy Observer base | ✅ ~~success~~ | [#35940651930](https://github.com/pl0n3r/brvtal/actions/runs/35940651930) |
| Smoke autenticado base | ⛔ **failure / diagnostic** | [#35940837144](https://github.com/pl0n3r/brvtal/actions/runs/35940837144) · eventDate PASS; timeout en `sets-relations`, operación aún no etiquetada |
| Entrega candidata | 🚧 cleanup acotado | preservar fallo primario y terminar proceso si Chromium no cierra |

## Huella del cambio

<!-- brvtal:git-delta -->

| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **2** | **+56** | **−45** | **+11** |

## Calidad y entrega

<!-- brvtal:gate-plan -->

| Control | Estado / contrato |
| --- | --- |
| Gates | **preflight · coordination · fast[JS] · chromium** |
| PR + snapshot exacto | Issue #631 · reserva `53201ee4-e329-438f-95fd-e89b7dcdbba5` |
| CodeRabbit / Sonar | 🚧 validar HEAD estable; máximo 3 rondas automáticas |
| CI del SHA exacto de main | 🚧 después del merge |
| Producción | 🚧 ejecutar smoke nuevo y exigir terminación + evidencia completa |

## Flujo de entrega

```mermaid
flowchart LR
 B["main v0.1.47 · 6bea701"] --> F["#631 · acotar cleanup"]
 F --> P["PR · browser-close bounded"] --> M["Squash merge"]
 M --> C["CI exact-main + Hostinger observer"] --> S["Smoke autenticado completo"]
```

## Qué se hizo

- Ejecutar la navegación de Admin únicamente por el selector canónico `[data-admin-nav]` y dentro de `runOperation`, eliminando el `button.count()` no acotado que dejó `failureOperation: null` al entrar a Sets.
- Mantener activo el watchdog global durante el cierre de Chromium.
- Tratar `browser.close()` como operación acotada de cleanup (máximo 10 s por defecto), persistiendo `cleanupError` si falla.
- Preservar el error primario cuando ya existe; un fallo adicional de cleanup no borra la causa inicial.
- Forzar salida no-cero después de persistir evidencia si Chromium no termina, de modo que los pasos `always()` puedan publicar el artefacto.
- Mantener el smoke estrictamente read-only y sin cambios de runtime/producto.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del segundo cierre de #631.
- `tests/e2e/production-authenticated-smoke.mjs` — cleanup de Chromium acotado y evidencia de fallo de cleanup.

## Validación

- ✅ ~~Base v0.1.47 exacta: CI #35940651923 y observer #35940651930 aprobaron sobre `6bea701a…`.~~
- ⛔ Smoke #35940837144 terminó `failure`: release exacto, health 200/DB connected, Home/DISCADMIN 200, dashboard **433 ms** sin 5xx, versión Admin exacta, Events workspace y fecha de Event PASS; watchdog global cortó en `sets-relations`, antes de Sets/Hero.
- ✅ ~~Artefacto real: `failureStage=sets-relations`, `failureOperation=null`; la navegación tenía un `button.count()` fuera de `runOperation`.~~
- ✅ ~~Auditoría estática: el watchdog se limpiaba antes de `browser.close()`, dejando además una ruta de cleanup fuera de su protección.~~
- 🚧 PR CI/Chromium, revisión, merge y smoke ejecutado final pendientes; **no declarar PRODUCTION GREEN** antes.

## Qué sigue

| Lane | Trabajo |
| --- | --- |
| **NOW** | 🚧 [#631](https://github.com/pl0n3r/brvtal/issues/631): cerrar cleanup, mergear y ejecutar smoke final. |
| **NEXT** | 🚧 [#533](https://github.com/pl0n3r/brvtal/issues/533): publicar `🟢 PRODUCTION GREEN` solo con las cinco evidencias. |
| **LATER** | 🚧 retomar backlog de producto después de GREEN y de la barrera global de Tanda 1. |
| **BLOCKED / EXTERNAL** | 🚧 Tanda 2 requiere factory `v1.0.0` y GREEN en los tres repos. |

## Panorama general pendiente

- 🚧 **NOW**: smoke final debe terminar y acreditar health/SHA/DB, Home/Admin/Dashboard, Events/date, Sets, Hero y ausencia de 5xx.
- 🚧 **NEXT**: cerrar #631 y registrar GREEN en #533.
- 🚧 **LATER**: esperar la Tanda 1 global antes de adoptar el kit factory.
- 🚧 **BLOCKED / EXTERNAL**: BRVTAL sigue sin GREEN hasta smoke final aprobado.
