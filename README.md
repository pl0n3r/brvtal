# BRVTAL — Último deploy

<p align="center">
<a href="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml"><img alt="BRVTAL CI" src="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg?branch=main"></a>
<a href="https://sonarcloud.io/dashboard?id=pl0n3r_brvtal"><img alt="Sonar Quality Gate" src="https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_brvtal&metric=alert_status"></a>
<a href="https://github.com/pl0n3r/brvtal/actions/workflows/production-deploy-observer.yml"><img alt="Deploy Observer" src="https://github.com/pl0n3r/brvtal/actions/workflows/production-deploy-observer.yml/badge.svg?branch=main"></a>
</p>

> Snapshot de **solo el deploy actual** para #698 / PR #699: coordinación repository-only, fail-closed y con una sola línea activa por repositorio. No modifica producto ni producción.

## Progress convention
- ✅ ~~Struck through~~ = completed and verified through required gates.
- 🚧 Normal text = pending/in progress.
- ⛔ = active production blocker.

## Estado del deploy
| Señal | Estado | Evidencia |
| --- | --- | --- |
| Work line | 🚧 **#698 · coordinación exclusiva** | `work/issue-698` · reserva `cb0a557f-3782-45df-8246-573d4a6805e0` |
| Base exacta | ✅ **main v0.1.59** | `f5a5f1b687d9972a9d898e9da6a618f85cf5cf08` |
| Versión de producto | ✅ **v0.1.59 sin cambio** | repository-only |
| Producción | ⛔ **NO GREEN · #681** | registry de migraciones pendiente |
| Factory labels | ⛔ **#694** | pendiente Factory v1.0.5 |

## Huella del cambio
<!-- brvtal:git-delta -->
| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **5** | **+461** | **−88** | **+373** |

## Calidad y entrega
<!-- brvtal:gate-plan -->
| Control | Estado / contrato |
| --- | --- |
| Gates esperados | **preflight · coordination · fast[PHP+JS] · database · chromium · real-stack** |
| PR + snapshot exacto | **#699 · coordinación repository-wide** |
| Roles | **Infrastructure · Software Engineering · QA · Security** |
| Review | BRVTAL CI + Factory Policy/Privacy + Sonar/CodeQL/CodeRabbit |
| CI del SHA exacto de main | 🚧 validar HEAD final antes de merge |
| Production GREEN | ⛔ fuera de alcance de este PR |

## Flujo de entrega
```mermaid
flowchart LR
  T["/take"] --> R["recovery-first"]
  R --> G["repo-wide guard"]
  G --> L["single active line"]
  L --> V["gates exact-head"]
```

## Qué se hizo
- `Work Coordination` serializa el repositorio con `cancel-in-progress: false` y `queue: max`.
- `/take` distingue `none / recovered / blocked` y nunca cae a una segunda reserva ante autoridad activa o incompatible.
- `reserve_work()` aplica el mismo guard como defensa para callers internos.
- `status: reserved` es estado derivado: un label manual nunca crea branch, assignee ni marker trusted.
- Regresiones cubren recovery, autoridad global, labels manuales, Issues cerrados y PR draft/ready.
- No hay cambios de runtime, DB, Hostinger, migraciones, secretos o deploy.

## Archivos modificados en este deploy
- `.github/workflows/work-coordination.yml`
- `README.md`
- `scripts/work_coordinator.py`
- `tests/project-operations-contract.php`
- `tests/test_work_coordinator.py`

## Validación
- ✅ Factory CI, Policy, Privacy, Sonar y CodeQL ya validaron el diseño previo del HEAD.
- ✅ Gate `coordination` confirmó la reserva y contrato de PR.
- 🚧 `fast` debe revalidar README + project-operations con la nueva exclusión mutua.
- 🚧 BRVTAL CI exact-head y CodeRabbit terminal antes del merge.

## Qué sigue
| Lane | Trabajo |
| --- | --- |
| **NOW** | 🚧 [#698](https://github.com/pl0n3r/brvtal/issues/698): validar y fusionar exclusión mutua de coordinación. |
| **NEXT** | 🚧 [#629](https://github.com/pl0n3r/brvtal/issues/629): recuperación segura DISCADMIN. |
| **LATER** | 🚧 [#683](https://github.com/pl0n3r/brvtal/issues/683): staff API D-060. |
| **BLOCKED / EXTERNAL** | 🚧 [#681](https://github.com/pl0n3r/brvtal/issues/681) producción · [#694](https://github.com/pl0n3r/brvtal/issues/694) Factory @v1. |

## Panorama general pendiente
- 🚧 **NOW:** #698 coordinación repository-wide.
- 🚧 **NEXT:** #629 recuperación segura DISCADMIN.
- 🚧 **LATER:** #683 staff API D-060.
- 🚧 **BLOCKED / EXTERNAL:** #681 producción; #694 Factory Labels.
