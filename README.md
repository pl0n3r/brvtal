# BRVTAL — Último deploy

<p align="center">
<a href="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml"><img alt="BRVTAL CI" src="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg?branch=main"></a>
<a href="https://sonarcloud.io/dashboard?id=pl0n3r_brvtal"><img alt="Sonar Quality Gate" src="https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_brvtal&metric=alert_status"></a>
<a href="https://github.com/pl0n3r/brvtal/actions/workflows/production-deploy-observer.yml"><img alt="Deploy Observer" src="https://github.com/pl0n3r/brvtal/actions/workflows/production-deploy-observer.yml/badge.svg?branch=main"></a>
</p>

> Snapshot de **solo el trabajo actual** para #715: estabilización determinista del E2E de failed-save del SEO workspace. Es un cambio test-only y no incrementa la versión de producto.

## Progress convention
- ✅ ~~Struck through~~ = completed and verified through required gates.
- 🚧 Normal text = pending/in progress.
- 🚧 = active production blocker.

## Estado
| Señal | Estado | Evidencia |
| --- | --- | --- |
| Work line | 🚧 **#715 · SEO failed-save retry E2E** | `work/issue-715` · reserva `9a28a004-d2b4-477e-89c0-0d385bb47d33` |
| Base | ✅ **main** | `19a0f9b383611cdf2caa3872b582eddcfd8ff616` |
| Versión | ✅ **v0.1.71** | test-only; sin bump de release |
| Producción | 🚧 **NO GREEN · #681** | migration registry parity sigue bloqueado externamente |

## Diagnóstico
- El runtime de `discadmin/seo-workspace.js` conserva el editor y los valores authored cuando el PUT falla.
- El test anterior verificaba el input inmediatamente después de `requestSubmit()`, antes de observar una transición explícita del Save asíncrono.
- La flake se estabiliza sin sleeps: primero se confirma que el preview refleja el texto authored, después que el PUT fallido llevó ese mismo valor, luego que llegó el feedback de error y finalmente que el editor conserva el input para retry.
- No se modifica runtime, API, DB, permisos, producción ni semántica del editor.

## Huella del cambio
<!-- brvtal:git-delta -->
| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **2** | **+TBD** | **−TBD** | **TBD** |

## Calidad y entrega
<!-- brvtal:gate-plan -->
| Control | Estado / contrato |
| --- | --- |
| Gates | 🚧 **preflight · coordination · fast[PHP+JS] · database · chromium · real-stack · webkit** |
| Factory | 🚧 Policy · Privacy · Labels |
| Main | 🚧 exact-main tras merge |
| Review | 🚧 Sonar + CodeRabbit |
| Producción | #681 fuera de alcance; no se declara GREEN |

## Qué sigue
| Lane | Trabajo |
| --- | --- |
| **NOW** | 🚧 [#715](https://github.com/pl0n3r/brvtal/issues/715): validar Chromium repetido y CI exact-head. |
| **LATER** | 🚧 [#533](https://github.com/pl0n3r/brvtal/issues/533): roadmap canónico. |
| **BLOCKED / EXTERNAL** | 🚧 [#681](https://github.com/pl0n3r/brvtal/issues/681): migration registry parity / producción NO GREEN. |
