# BRVTAL — Último deploy

<p align="center">
<a href="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml"><img alt="BRVTAL CI" src="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg?branch=main"></a>
<a href="https://sonarcloud.io/dashboard?id=pl0n3r_brvtal"><img alt="Sonar Quality Gate" src="https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_brvtal&metric=alert_status"></a>
<a href="https://github.com/pl0n3r/brvtal/actions/workflows/production-deploy-observer.yml"><img alt="Deploy Observer" src="https://github.com/pl0n3r/brvtal/actions/workflows/production-deploy-observer.yml/badge.svg?branch=main"></a>
</p>

> Snapshot de **solo el deploy actual** para #681: compatibilidad del reconciliador con el runtime PHP real de Hostinger.

## Progress convention
- ✅ ~~Struck through~~ = completed and verified through required gates.
- 🚧 Normal text = pending/in progress.
- 🚧 = active production blocker.

## Estado del deploy
| Señal | Estado | Evidencia |
| --- | --- | --- |
| Work line | 🚧 **#681 · production migration registry parity** | `work/issue-681` · reserva `7b4511c3-b2ba-4f1f-ac96-be1202298d12` |
| Base | ✅ **main** | `b79f9257795d980bdaa4a18fb25dc898e7f18642` · v0.1.73 |
| Versión | 🚧 **v0.1.74** | patch deploy-bound |
| PR | 🚧 **pending** | `work/issue-681` → `main` |
| Main | 🚧 **pending** | CI del SHA exacto tras merge |
| Producción | 🚧 **NO GREEN** | reconcile → health → authenticated smoke pendientes |

## Huella del cambio
<!-- brvtal:git-delta -->
| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **5** | **+47** | **−50** | **−3** |

## Calidad y entrega
<!-- brvtal:gate-plan -->
| Control | Estado / contrato |
| --- | --- |
| Gates | 🚧 **preflight · coordination · fast[PHP+JS] · database · chromium · real-stack · webkit** |
| Factory | 🚧 Policy · Privacy · Labels |
| Snapshot | 🚧 PR + snapshot exacto |
| Main | 🚧 CI del SHA exacto de main tras merge |
| Review | 🚧 Sonar + CodeRabbit |
| Producción | 🚧 backup → reconcile → verify-plan → health → authenticated smoke |

## Qué se hizo
- v0.1.73 quedó desplegada exactamente en producción y CI/Deploy Observer están verdes.
- El reconcile owner-only falló **antes del backup y antes de cualquier escritura** durante `reconcile-plan`.
- Diagnóstico read-only probado contra el mismo SHA desplegado: Hostinger ejecuta **PHP 8.2.33** y el transporte SSH funciona.
- La causa exacta es `array_all()` en `brvtalMigrationProofIsFullyAbsent()`; esa función no existe en PHP 8.2.
- Se reemplaza por un recorrido equivalente y fail-closed: solo devuelve true cuando cada requisito es exactamente `false`.
- La regresión impide reintroducir `array_all(` en el reconciliador y verifica fully-absent vs mixed.
- El workflow diagnóstico temporal **no forma parte del diff final**.

## Archivos modificados en este deploy
- `README.md` — snapshot exacto de recuperación v0.1.74.
- `config/migration_reconcile.php` — compatibilidad PHP 8.2 sin cambiar la semántica de proof.
- `config/version.php` — versión v0.1.74.
- `package.json` — versión sincronizada.
- `tests/migrations-contract.php` — regresión PHP 8.2 + fail-closed.

## Validación
- El proof fully absent debe seguir habilitando solo la ruta ya protegida del media guard.
- Proof mixto debe devolver false y mantener el reconcile bloqueado.
- No se relaja el scanner SQL ni se cambia backup-before-write.
- Tras merge: CI exact-main → Deploy Observer → reconcile canónico → `verify-plan __NONE__` → health 200 exacto → authenticated smoke PASS.

## Qué sigue
| Lane | Trabajo |
| --- | --- |
| **NOW** | 🚧 [#681](https://github.com/pl0n3r/brvtal/issues/681): integrar v0.1.74 y repetir reconcile seguro. |
| **NEXT** | 🚧 Cerrar #681 solo con backup/reconcile + health + authenticated smoke exactos. |
| **LATER** | 🚧 [#533](https://github.com/pl0n3r/brvtal/issues/533): retomar roadmap cuando producción vuelva a GREEN. |

## Panorama general pendiente
- 🚧 **NOW**: #681, restaurar migration registry parity con backup-before-write.
- 🚧 **NEXT**: declarar GREEN únicamente con exact SHA/version/schema + smoke autenticado.
- 🚧 **LATER**: #533 roadmap canónico.
