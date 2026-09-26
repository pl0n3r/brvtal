# BRVTAL — Último deploy

<p align="center">
<a href="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml"><img alt="BRVTAL CI" src="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg?branch=main"></a>
<a href="https://sonarcloud.io/dashboard?id=pl0n3r_brvtal"><img alt="Sonar Quality Gate" src="https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_brvtal&metric=alert_status"></a>
<a href="https://github.com/pl0n3r/brvtal/actions/workflows/production-deploy-observer.yml"><img alt="Deploy Observer" src="https://github.com/pl0n3r/brvtal/actions/workflows/production-deploy-observer.yml/badge.svg?branch=main"></a>
</p>

> Snapshot de **solo el deploy actual** para #389 / PR #704: backups automáticos server-side, scopes seleccionables, retención segura y boundary off-site para Google Drive. No declara producción GREEN.

## Progress convention
- ✅ ~~Struck through~~ = completed and verified through required gates.
- 🚧 Normal text = pending/in progress.
- ⛔ = active production blocker.

## Estado del deploy
| Señal | Estado | Evidencia |
| --- | --- | --- |
| Work line | 🚧 **#389 · backup automation** | `work/issue-389` · reserva `c6d63ab6-3a05-4897-a5a0-d065f880314c` |
| Base exacta | ✅ **main** | `d9cca3a738650e7ae7d6f3fcafe95c7859b75362` |
| Versión de producto | 🚧 **v0.1.63** | `config/version.php` + `package.json` |
| Producción | ⛔ **NO GREEN · #681** | recovery de migraciones sigue bloqueado |
| Factory Labels | ⛔ **#694** | Factory `@v1` todavía falla por alias/canónica |
| PR | 🚧 **#704** | exact-head gates obligatorios |

## Huella del cambio
<!-- brvtal:git-delta -->
| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **13** | **+731** | **−83** | **+648** |

## Calidad y entrega
<!-- brvtal:gate-plan -->
| Control | Estado / contrato |
| --- | --- |
| Gates esperados | **preflight · coordination · fast[PHP+JS] · database · chromium · real-stack · webkit** |
| PR + snapshot exacto | **#704 · #389 backup automation** |
| Roles | **Infrastructure · Software Engineering · Security · QA** |
| Review | BRVTAL CI + Factory Policy/Privacy + Sonar/CodeRabbit |
| CI del SHA exacto de main | 🚧 obligatorio después del merge |
| Production GREEN | ⛔ fuera de alcance mientras #681 siga abierto |

## Qué se hizo
- Scheduler CLI compatible con Hostinger Cron, sin daemon ni browser abierto.
- Cadencias every N hours, every N days y daily a hora local en `America/Bogota`.
- Scopes canónicos `FULL`, `DATABASE` y `MEDIA`, sin segundo motor de backup.
- Lock no bloqueante para impedir ejecuciones simultáneas.
- Idempotencia por occurrence: una corrida recuperada no duplica un backup ya finalizado.
- Retención local limitada exclusivamente a backups `trigger=scheduled`; manuales no se eliminan.
- Estado privado y atómico bajo `storage/backups/.automation`.
- DISCADMIN muestra configuración, next run, last result, scope y retención.
- Boundary Google Drive testeable mediante transporte inyectable; tokens OAuth no se persisten ni viajan al frontend.
- Fallo de Drive queda separado de éxito local.
- Restore automático sigue explícitamente fuera de alcance.

## Archivos del deploy
- `config/backup_automation.php`
- `config/backups.php`
- `config/version.php`
- `discadmin/backups.css`
- `discadmin/backups.js`
- `discadmin/backups.php`
- `package.json`
- `scripts/backup-scheduler.php`
- `tests/backups-automation-contract.php`
- `tests/backups-contract.php`
- `tests/e2e/discadmin-backups.spec.mjs`
- `tests/integration/backups.php`
- `README.md`

## Validación
- 🚧 BRVTAL CI sobre HEAD exacto de PR #704.
- 🚧 Factory Policy/Privacy y Sonar/CodeRabbit terminales antes de merge.
- 🚧 Validación exact-main obligatoria tras integración.
- Google OAuth real requiere credenciales/autoridad externa y no se simula como conectado.

## Qué sigue
| Lane | Trabajo |
| --- | --- |
| **NOW** | 🚧 [#389](https://github.com/pl0n3r/brvtal/issues/389): cerrar gates de backup automation. |
| **NEXT** | 🚧 siguiente Issue disponible por dispatcher. |
| **BLOCKED / EXTERNAL** | ⛔ [#681](https://github.com/pl0n3r/brvtal/issues/681): producción no GREEN. |
| **BLOCKED / FACTORY** | ⛔ [#694](https://github.com/pl0n3r/brvtal/issues/694): Factory Labels `@v1`. |
