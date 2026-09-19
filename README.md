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
| Work line | 🚧 **#564 CI throughput telemetry (v0.1.17), PR #565** | un solo owner abre el registro tras navegación |
| Base exacta | ✅ **VALIDATED IN CODE** | `main` `2a57147e3c7b0ba9b1176e0293d501144a510b8f` |
| Version | 🚧 **0.1.16 → 0.1.17** | patch deploy |
| Producción | 🚧 **PENDING MERGE / OBSERVATION** | no se infiere validación de producción desde CI |

## Huella del cambio

<!-- brvtal:git-delta -->

| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **5** | **+100** | **−5** | **+95** |

## Calidad y entrega

<!-- brvtal:gate-plan -->

| Control | Estado / contrato |
| --- | --- |
| Navegación Content Health | `content-core-nav.js` es el único owner del click OPEN |
| Sincronización | Playwright espera el resultado compuesto de sección + registro + feedback |
| Gates | **preflight · fast[PHP+JS] · database · chromium · real-stack · webkit** |
| Sonar + CodeRabbit | paralelo sobre head estable |
| Exact-main | CI del SHA exacto de main tras squash merge |

## Flujo de entrega

```mermaid
flowchart LR
 A["Release v0.1.17"] --> P["PR + snapshot exacto"]
 P --> Q["CI / Sonar / CodeRabbit"]
 Q --> M["Squash merge"]
 M --> X["CI del SHA exacto de main"]
 X --> O["Observer de deploy"]
```

## Qué se hizo

- Se añade telemetría de wall time al DAG de BRVTAL CI sin serializar gates.
- El preflight expone el epoch inicial y validate publica la duración agregada.
- Se mantiene intacto el fan-out paralelo de database, Chromium, real-stack, WebKit y recovery.
- La regresión Content Health ahora muta y acciona el botón atómicamente para probar el fallback real sin competir con el rerender del dashboard.
- Versión **0.1.17**.

## Archivos modificados en este deploy

- `.github/workflows/update-release-metadata.yml`
- `README.md`
- `config/version.php`
- `package.json`
- `tests/e2e/discadmin-content-health-navigation.spec.mjs`

## Validación

- Preflight, MariaDB, real-stack, WebKit y recovery ya pasaron sobre la implementación de telemetría.
- Chromium expuso la carrera del harness Content Health; se corrige sin aumentar timeouts ni relajar expectativas funcionales.
- BRVTAL CI, Sonar y CodeRabbit deben cerrar sobre el head estable antes del merge.
- No se declara producción validada desde CI.

## Qué sigue

| Lane | Trabajo |
| --- | --- |
| **NOW** | 🚧 [#564](https://github.com/pl0n3r/brvtal/issues/564) · medir y reducir el critical path del pipeline. |
| **NEXT** | 🚧 [#174](https://github.com/pl0n3r/brvtal/issues/174) · proteger cambios sin guardar en Banners. |
| **LATER** | 🚧 [#519](https://github.com/pl0n3r/brvtal/issues/519), [#518](https://github.com/pl0n3r/brvtal/issues/518), [#257](https://github.com/pl0n3r/brvtal/issues/257) · productividad editorial y protección general de editores. |
| **BLOCKED / EXTERNAL** | 🚧 [#534](https://github.com/pl0n3r/brvtal/issues/534) · verificación de Hostinger/hPanel separada. |

## Panorama general pendiente

| Lane | Frente | Issues |
| --- | --- | --- |
| **DONE** | ✅ ~~Editor/modal navigation lifecycle~~ | ✅ ~~[#216](https://github.com/pl0n3r/brvtal/issues/216) · v0.1.15 / PR #562~~ |
| **NOW** | 🚧 CI throughput telemetry | 🚧 [#564](https://github.com/pl0n3r/brvtal/issues/564) |
| **NEXT** | 🚧 Unsaved Banners protection | 🚧 [#174](https://github.com/pl0n3r/brvtal/issues/174) |
| **LATER** | 🚧 Editorial productivity | 🚧 [#519](https://github.com/pl0n3r/brvtal/issues/519), [#518](https://github.com/pl0n3r/brvtal/issues/518), [#257](https://github.com/pl0n3r/brvtal/issues/257) |
| **BLOCKED / EXTERNAL** | 🚧 Hostinger deploy observation | 🚧 [#534](https://github.com/pl0n3r/brvtal/issues/534) |
