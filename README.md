# BRVTAL — Último deploy

<p align="center">
<a href="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml"><img alt="BRVTAL CI" src="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg?branch=main"></a>
<a href="https://sonarcloud.io/dashboard?id=pl0n3r_brvtal"><img alt="Sonar Quality Gate" src="https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_brvtal&metric=alert_status"></a>
<a href="https://github.com/pl0n3r/brvtal/actions/workflows/production-deploy-observer.yml"><img alt="Deploy Observer" src="https://github.com/pl0n3r/brvtal/actions/workflows/production-deploy-observer.yml/badge.svg?branch=main"></a>
</p>

> Snapshot repository-only para #698 / PR #699: coordinación fail-closed con una sola línea activa por repositorio. No modifica producto ni producción.

## Estado del deploy
| Señal | Estado | Evidencia |
| --- | --- | --- |
| Work line | 🚧 **#698 · coordinación exclusiva** | `work/issue-698` · reserva `37420d44-b8ed-40ed-87c2-3832c759a794` |
| Base exacta | ✅ **main v0.1.59** | `f5a5f1b687d9972a9d898e9da6a618f85cf5cf08` |
| Versión de producto | ✅ **v0.1.59 sin cambio** | repository-only |
| Producción | ⛔ **NO GREEN · #681** | registry de migraciones pendiente |
| Factory labels | ⛔ **#694** | pendiente Factory v1.0.5 |

## Huella del cambio
<!-- brvtal:git-delta -->
| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **4** | **+271** | **-80** | **+191** |

## Qué se hizo
- `Work Coordination` usa una concurrency group fija del repositorio y `cancel-in-progress: false`.
- `/take` distingue `none / recovered / blocked` y nunca cae a una segunda reserva ante autoridad activa/incompatible.
- `reserve_work()` aplica el mismo guard como defensa para callers internos.
- Se cubren reserva reciente, stale recuperable, stale incompatible, release→take y el contrato de serialización del workflow.
- No hay cambios de runtime, DB, Hostinger, migraciones, secretos o deploy.

## Calidad y entrega
| Control | Estado / contrato |
| --- | --- |
| Gates esperados | **preflight · coordination · fast[PHP+JS] · database · chromium · real-stack** |
| PR + snapshot exacto | **#699 · coordinación repository-wide** |
| Roles | **Infrastructure · Software Engineering · QA · Security** |
| Review | BRVTAL CI + Factory Policy/Privacy + Sonar/CodeQL/CodeRabbit |
| CI exact-head | 🚧 pendiente sobre HEAD estable |
| Production GREEN | ⛔ fuera de alcance de este PR |

## Qué sigue
| Lane | Trabajo |
| --- | --- |
| **NOW** | 🚧 [#698](https://github.com/pl0n3r/brvtal/issues/698): validar y fusionar exclusión mutua de coordinación. |
| **NEXT** | 🚧 [#629](https://github.com/pl0n3r/brvtal/issues/629): recuperación segura DISCADMIN. |
| **LATER** | 🚧 [#683](https://github.com/pl0n3r/brvtal/issues/683): staff API D-060. |
| **BLOCKED / EXTERNAL** | 🚧 [#681](https://github.com/pl0n3r/brvtal/issues/681) producción · [#694](https://github.com/pl0n3r/brvtal/issues/694) Factory @v1. |
