# BRVTAL — Último deploy

<p align="center">
<a href="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml"><img alt="BRVTAL CI" src="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg?branch=main"></a>
<a href="https://sonarcloud.io/dashboard?id=pl0n3r_brvtal"><img alt="Sonar Quality Gate" src="https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_brvtal&metric=alert_status"></a>
<a href="https://github.com/pl0n3r/brvtal/actions/workflows/production-deploy-observer.yml"><img alt="Deploy Observer" src="https://github.com/pl0n3r/brvtal/actions/workflows/production-deploy-observer.yml/badge.svg?branch=main"></a>
</p>

> Snapshot de **solo el deploy actual** para #715: estabilización determinista del E2E de failed-save del SEO workspace. Es test-only y no incrementa la versión de producto.

## Progress convention
- ✅ ~~Struck through~~ = completed and verified through required gates.
- 🚧 Normal text = pending/in progress.
- 🚧 = active production blocker.

## Estado del deploy
| Señal | Estado | Evidencia |
| --- | --- | --- |
| Work line | 🚧 **#715 · SEO failed-save retry E2E** | `work/issue-715` · reserva `9a28a004-d2b4-477e-89c0-0d385bb47d33` |
| Base | ✅ **main** | `19a0f9b383611cdf2caa3872b582eddcfd8ff616` |
| Versión | ✅ **v0.1.71** | test-only; sin bump de release |
| PR | 🚧 **delivery de #715** | PR + snapshot exacto |
| Main | 🚧 **post-merge** | CI del SHA exacto de main |
| Producción | 🚧 **NO GREEN · #681** | migration registry parity sigue bloqueado externamente |

## Huella del cambio
<!-- brvtal:git-delta -->
| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **2** | **+39** | **−64** | **−25** |

## Calidad y entrega
<!-- brvtal:gate-plan -->
| Control | Estado / contrato |
| --- | --- |
| Gates | 🚧 **preflight · coordination · fast[PHP+JS] · chromium** |
| Factory | 🚧 Policy · Privacy · Labels |
| Snapshot | 🚧 PR + snapshot exacto |
| Main | 🚧 CI del SHA exacto de main tras merge |
| Review | 🚧 Sonar + CodeRabbit |
| Producción | #681 fuera de alcance; no se declara GREEN |

## Flujo de entrega
```mermaid
flowchart LR
  E["fill authored SEO description"] --> P["preview reflects authored value"]
  P --> S["explicit Save"]
  S --> W["PUT captured with authored payload"]
  W --> F["server returns SEO_WRITE_FAILED"]
  F --> U["error feedback/status visible"]
  U --> R["editor remains open with authored input"]
```

## Qué se hizo
- El runtime de `discadmin/seo-workspace.js` no cambia: ya preserva el editor y los valores authored cuando el PUT falla.
- El E2E ahora espera que el preview refleje el texto authored antes del submit.
- Después confirma que el PUT fallido recibió exactamente ese `seo_description`.
- Espera el feedback de error explícito antes de validar el estado final de UI.
- Finalmente exige que el editor siga visible y el input conserve el texto para retry.
- No se añaden sleeps arbitrarios ni cambios de API, DB, permisos o producción.

## Archivos modificados en este deploy
- `README.md`
- `tests/e2e/discadmin-seo-workspace.spec.mjs`

## Validación
- Chromium debe ejecutar el caso failed-save sobre señales UI/network/state explícitas.
- Sonar exact-head debe permanecer sin issues/hotspots nuevos.
- CodeRabbit no debe dejar findings accionables.
- Work Coordination y Factory gates deben pasar.
- La versión permanece v0.1.71 porque el cambio es test-only.
- 🚧 Evidencia exact-head se registra solo después de completar gates.

## Qué sigue
| Lane | Trabajo |
| --- | --- |
| **NOW** | 🚧 [#715](https://github.com/pl0n3r/brvtal/issues/715): validar Chromium y merge. |
| **LATER** | 🚧 [#533](https://github.com/pl0n3r/brvtal/issues/533): roadmap canónico. |
| **BLOCKED / EXTERNAL** | 🚧 [#681](https://github.com/pl0n3r/brvtal/issues/681): migration registry parity / producción NO GREEN. |

## Panorama general pendiente
- 🚧 **NOW**: #715, estabilización test-only del failed-save SEO.
- 🚧 **LATER**: #533 roadmap canónico.
- 🚧 **BLOCKED / EXTERNAL**: #681 permanece fail-closed; sin backup/autoridad verificable no hay reconcile.
