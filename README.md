# BRVTAL — Último deploy

<p align="center">
  <a href="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml"><img alt="BRVTAL CI" src="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg?branch=main"></a>
  <a href="https://sonarcloud.io/dashboard?id=pl0n3r_brvtal"><img alt="Sonar Quality Gate" src="https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_brvtal&metric=alert_status"></a>
  <a href="https://github.com/pl0n3r/brvtal/actions/workflows/production-deploy-observer.yml"><img alt="Deploy Observer" src="https://github.com/pl0n3r/brvtal/actions/workflows/production-deploy-observer.yml/badge.svg?branch=main"></a>
</p>

> **Development dashboard** · snapshot de **solo el deploy actual** para PR #574 / v0.1.24.

## Progress convention

- ✅ ~~Struck through~~ = completed and verified through the required delivery gates.
- 🚧 Normal text = pending or currently in progress.

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Work line | 🚧 **#257 · unsaved editor protection v0.1.24** | PR #574 · findings Major en corrección |
| Base exacta | ✅ ~~main validado~~ | `f1eeb5c355ea2a799b016493e2100afd35510c57` · BRVTAL CI / Sonar / Deploy Observer success |
| Versión | 🚧 **0.1.23 → 0.1.24** | cambio deploy-bound de DISCADMIN |
| Producción base | ✅ ~~release observado~~ | identidad del release base; no implica comportamiento validado |

## Huella del cambio

<!-- brvtal:git-delta -->

| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **17** | **+1039** | **−90** | **+949** |

## Calidad y entrega

<!-- brvtal:gate-plan -->

| Control | Estado / contrato |
| --- | --- |
| Gates | **preflight · coordination · fast[PHP+JS] · database · chromium · real-stack · webkit** |
| Reservation | Issue #257 · `work/issue-257` · UUID vigente |
| PR integrity | **PR + snapshot exacto** · rebase lógico sobre el main actual |
| Dirty-state | snapshot + lock + operation token; completion stale = no-op |
| Navigation | mutación tardía = rollback de workspace/URL + editor dirty preservado |
| Auth expiry | 401/Logout no pueden renderizar y destruir cambios sin guardar |
| Sonar / CodeRabbit | nueva revisión sobre el head estable |
| Exact-main | **CI del SHA exacto de main** después del squash merge |

## Flujo de entrega

```mermaid
flowchart LR
 I["#257 / PR #574"] --> R["Rebase sobre main"]
 R --> G["Tokenized dirty guard"]
 G --> A["Rollback + auth preservation"]
 A --> T["Contracts + Playwright"]
 T --> P["CI · Sonar · CodeRabbit"]
 P --> M["Squash merge"]
 M --> X["Exact-main validation"]
```

## Qué se hizo

- Se conserva un único manager de cambios sin guardar para CRUD legacy, Lineup, Releases, Blog y Content Core.
- Navegaciones asíncronas y Logout ahora poseen token de operación; una respuesta stale no puede liberar el lock de otra operación.
- El snapshot se revalida antes del commit; una mutación tardía restaura workspace y URL previos sin perder el editor dirty.
- Logout coordina Unsaved Changes + Hero guard y evita render/commit dependiente si el snapshot cambió.
- Un 401 administrativo con cambios dirty conserva el DOM editable y muestra estado explícito de sesión terminada.
- Playwright cubre carrera de navegación, rollback, Logout y expiración de sesión.
- Los findings nuevos de Sonar se corrigen en el mismo head: retornos uniformes para las transacciones de navegación y menor complejidad en expiración de sesión.

## Archivos modificados en este deploy

- `README.md`
- `config/version.php`
- `discadmin/admin-auth-boundary.js`
- `discadmin/admin-information-architecture.js`
- `discadmin/admin-modules.js`
- `discadmin/admin-reliability.js`
- `discadmin/admin-unsaved-changes.js`
- `discadmin/blog.js`
- `discadmin/content-core.js`
- `discadmin/index-core.php`
- `discadmin/index.php`
- `discadmin/releases.js`
- `docs/BRVTAL-SPEC.md`
- `package.json`
- `tests/e2e/admin-reliability-quick-wins.spec.mjs`
- `tests/e2e/discadmin-information-architecture.spec.mjs`
- `tests/e2e/discadmin-unsaved-changes.spec.mjs`

## Validación

- Base exacta `f1eeb5c355ea2a799b016493e2100afd35510c57`: BRVTAL CI / `validate`, Sonar y Deploy Observer success.
- El head final de #574 debe volver a pasar los gates aplicables, Sonar y CodeRabbit.
- Sin migraciones ni operaciones destructivas de producción.
- Deploy observado no se confundirá con **VALIDATED IN PRODUCTION**.

## Qué sigue

| Lane | Trabajo |
| --- | --- |
| **NOW** | 🚧 [#257](https://github.com/pl0n3r/brvtal/issues/257) / PR #574 · cerrar revisión y v0.1.24. |
| **NEXT** | 🚧 [#525](https://github.com/pl0n3r/brvtal/issues/525) · rich Blog editor + safe HTML source mode. |
| **LATER** | 🚧 [#524](https://github.com/pl0n3r/brvtal/issues/524), [#528](https://github.com/pl0n3r/brvtal/issues/528), [#529](https://github.com/pl0n3r/brvtal/issues/529). |
| **BLOCKED / EXTERNAL** | 🚧 Sin bloqueo externo activo para #257. |

## Panorama general pendiente

| Lane | Frente | Issues |
| --- | --- | --- |
| **NOW** | 🚧 Unsaved editor protection | 🚧 [#257](https://github.com/pl0n3r/brvtal/issues/257) |
| **NEXT** | 🚧 Rich Blog editor | 🚧 [#525](https://github.com/pl0n3r/brvtal/issues/525) |
| **LATER** | 🚧 Membership / drafts / preview | 🚧 [#524](https://github.com/pl0n3r/brvtal/issues/524), [#528](https://github.com/pl0n3r/brvtal/issues/528), [#529](https://github.com/pl0n3r/brvtal/issues/529) |
| **BLOCKED / EXTERNAL** | 🚧 Protected production actions | requieren confirmación explícita cuando apliquen |
