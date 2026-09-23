# BRVTAL — Último deploy

<p align="center">
  <a href="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml"><img alt="BRVTAL CI" src="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg?branch=main"></a>
  <a href="https://sonarcloud.io/dashboard?id=pl0n3r_brvtal"><img alt="Sonar Quality Gate" src="https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_brvtal&metric=alert_status"></a>
  <a href="https://github.com/pl0n3r/brvtal/actions/workflows/production-deploy-observer.yml"><img alt="Deploy Observer" src="https://github.com/pl0n3r/brvtal/actions/workflows/production-deploy-observer.yml/badge.svg?branch=main"></a>
</p>

> **Development dashboard** · snapshot de **solo el deploy actual** para Issue #606.

## Progress convention

- ✅ ~~Struck through~~ = completed and verified through the required delivery gates.
- 🚧 Normal text = pending or currently in progress.

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Work line | 🚧 **#606 · Concept 05 cross-viewport fidelity QA** | child enfocado de #583 |
| Base exacta | ✅ ~~main v0.1.37 exact-main CI + Sonar + deploy/performance verdes~~ | `463e45a1f2c4d85114f97dabf80a6a770a4dcaab` |
| Versión | 🚧 **0.1.38** | QA + reducción de motion GSAP en coarse pointer |
| Producción | 🚧 pendiente de merge → exact-main → Deploy Observer | runtime público modificado |

## Huella del cambio

<!-- brvtal:git-delta -->

| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **5** | **+476** | **−61** | **+415** |

## Calidad y entrega

<!-- brvtal:gate-plan -->

| Control | Estado / contrato |
| --- | --- |
| Gates | **preflight · coordination · fast[PHP+JS] · database · chromium · real-stack · webkit** |
| PR integrity | **PR + snapshot exacto** · Issue #606 · `work/issue-606` · UUID `1dd29fe7-f1d7-4eec-a7a8-0087ae9413fd` |
| Fidelity matrix | 🚧 390 · 430 · 768 · 1024 · 1280 · 1440 · 1728 · 1920 |
| Accessibility | 🚧 keyboard focus · touch targets · reduced motion |
| Sonar | 🚧 análisis del HEAD final |
| CodeRabbit | 🚧 full review sobre HEAD final |
| CI del SHA exacto de main | 🚧 después del squash merge |

## Flujo de entrega

```mermaid
flowchart LR
 B["main v0.1.37 verde"] --> Q["#606 FIDELITY MATRIX"]
 Q --> P["PR · CI · Sonar · CodeRabbit"]
 P --> M["Squash merge"]
 M --> X["Exact-main CI"]
 X --> D["Deploy Observer + Performance"]
 D --> C["Parent #583 closeout evidence"]
```

## Qué se hizo

- Se añadió una suite integrada Concept 05 que monta shell, Hero, Next Experience, Nights, Artists, Sound, Memories, Journal, Connected y footer con los CSS reales.
- La matriz cubre **390 / 430 / 768 / 1024 / 1280 / 1440 / 1728 / 1920** y verifica contención del documento, geometría de bloques, títulos largos y colisiones del header.
- 390 y 1440 tienen aserciones específicas para preservar composiciones authored distintas, no un simple escalado.
- Se validan targets táctiles, foco visible y reduced-motion con GSAP/ScrollTrigger neutralizados.
- El fixture es determinista y usa contenido largo deliberado para revelar regresiones sin inventar relaciones de producción.
- Chromium expuso dos errores de modelado del test, corregidos sin tocar layout; CodeRabbit encontró además que el test reduced-motion estaba neutralizado por el hook visual determinista.
- La motion foundation ahora omite el trabajo GSAP no esencial en coarse pointers, además de reduced-motion/theme-minimal; se añadió control positivo para confirmar que fine pointer mantiene motion cuando está permitido.
- Ese ajuste de runtime convierte el slice en **v0.1.38 deploy-bound**.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto de #606 y gates seleccionados.
- `config/version.php` — versión humana v0.1.38.
- `js/public-concept05-motion.js` — reduced/coarse-pointer motion guard.
- `package.json` — versión de producto v0.1.38.
- `tests/e2e/public-concept05-fidelity-matrix.spec.mjs` — matriz integrada responsive/a11y/reduced-motion.

## Validación

- Base exacta `463e45a1f2c4d85114f97dabf80a6a770a4dcaab`: v0.1.37 con BRVTAL CI/`validate`, Sonar y Deploy Observer verdes.
- #606 está reservado por el coordinador; `work/issue-606` nació idéntica a esa base y no existe PR competidor.
- #583 permanece abierto como contrato padre; este PR no debe cerrarlo.
- Pendiente: revalidar la matriz y el runtime v0.1.38 en CI/Sonar/CodeRabbit, squash, exact-main y observación de producción.

## Qué sigue

| Lane | Trabajo |
| --- | --- |
| **NOW** | 🚧 [#606](https://github.com/pl0n3r/brvtal/issues/606) · cerrar matriz transversal Concept 05. |
| **NEXT** | 🚧 [#583](https://github.com/pl0n3r/brvtal/issues/583) · consolidar evidencia final del contrato visual sin PR monolítico. |
| **LATER** | 🚧 [#398](https://github.com/pl0n3r/brvtal/issues/398) · archivo cultural conectado; [#531](https://github.com/pl0n3r/brvtal/issues/531) · Media Library smarter. |
| **BLOCKED / EXTERNAL** | 🚧 Sin bloqueos externos activos. |

## Panorama general pendiente

| Lane | Frente | Issues |
| --- | --- | --- |
| **NOW** | 🚧 Concept 05 fidelity closeout | 🚧 [#606](https://github.com/pl0n3r/brvtal/issues/606) |
| **NEXT** | 🚧 Parent visual contract | 🚧 [#583](https://github.com/pl0n3r/brvtal/issues/583) |
| **LATER** | 🚧 Public archive / media scale | 🚧 [#398](https://github.com/pl0n3r/brvtal/issues/398), [#531](https://github.com/pl0n3r/brvtal/issues/531) |
