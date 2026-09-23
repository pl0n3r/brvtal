# BRVTAL — Último deploy

<p align="center">
  <a href="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml"><img alt="BRVTAL CI" src="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg?branch=main"></a>
  <a href="https://sonarcloud.io/dashboard?id=pl0n3r_brvtal"><img alt="Sonar Quality Gate" src="https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_brvtal&metric=alert_status"></a>
  <a href="https://github.com/pl0n3r/brvtal/actions/workflows/production-deploy-observer.yml"><img alt="Deploy Observer" src="https://github.com/pl0n3r/brvtal/actions/workflows/production-deploy-observer.yml/badge.svg?branch=main"></a>
</p>

> **Development dashboard** · snapshot exclusivo del deploy actual para Issue #592.

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Work line | 🚧 **#592 · Concept 05 NIGHTS + ARTISTS authored 1440/390** | parent #583 |
| Base exacta | ✅ ~~main v0.1.28 validado + desplegado + performance observado~~ | `409e544375104fc41aa83762187c30d26eefa3cb` |
| Versión | 🚧 **0.1.29** | cambio visible de producto/runtime público |
| Producción | 🚧 pendiente de PR → merge → exact-main | sin migración de esquema |

## Huella del cambio

<!-- brvtal:git-delta -->

| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **13** | **+000** | **−000** | **+000** |

Archivos del slice:

- `README.md`
- `config/public_home.php`
- `config/version.php`
- `css/public-concept05-nights-artists.css`
- `index.php`
- `js/app.js`
- `js/public-concept05-nights-artists.js`
- `js/public-home-visual.js`
- `js/public-roster.js`
- `package.json`
- `tests/e2e/public-concept05-nights-artists.spec.mjs`
- `tests/e2e/public-roster-phase-c.spec.mjs`
- `tests/public-concept05-dressing-contract.php`

## Calidad y entrega

<!-- brvtal:gate-plan -->

| Control | Estado / contrato |
| --- | --- |
| preflight / coordination | 🚧 requeridos sobre head estable |
| fast | 🚧 PHP 8.5 contracts + JS syntax + README exact snapshot |
| database | 🚧 requerido por contratos públicos compartidos |
| chromium | 🚧 geometría/behavior 1440 + 390 + payload real |
| real-stack | 🚧 runtime público integrado |
| webkit-totp | 🚧 compatibilidad browser/auth transversal |
| Sonar | 🚧 Quality Gate sobre head estable |
| CodeRabbit | 🚧 review final sobre head estable |

## Qué se hizo

- **02 / NIGHTS** deja de ser una card genérica: usa Events activos + `archive.events` reales del mismo payload público, deduplica por identidad y conserva navegación horizontal nativa.
- Cada Night enlaza a `/events/{slug}` cuando existe slug; nunca inventa una ruta. Ticket CTA usa `ticket_url` o Ticket Type público activo y desaparece para archivo/sold-out.
- **03 / ARTISTS** reutiliza `public-roster.js` como renderer canónico; perfiles permanecen en `/artists/{slug}` y Collective Status sigue gobernando orden/metadata.
- Nueva composición Concept 05 separada para 1440/390: 3–4 Nights densas en desktop, swipe card ~84vw en mobile, Artists 4→2 columnas con retratos documentales.
- Media de Nights/Artists falla cerrada; no aparece broken-image chrome y el contenido textual/canónico permanece usable.
- El enhancer Concept 05 no hace requests: observa los renderers existentes y solo añade composición/media/CTA honestos.
- Cobertura nueva para payload compartido, active+archive, dedupe, Event/Artist canonical links, Ticket Types, missing media, long copy, mobile overflow y reduced-motion/visual-test.

## Próximo paso

1. Abrir PR de #592 desde el head estable.
2. Ejecutar BRVTAL CI + Sonar + CodeRabbit en paralelo.
3. Corregir findings válidos sin ampliar scope.
4. Squash merge y validar el SHA exacto de `main`.
5. Observar deploy/performance y continuar #583 con **04 / SOUND + 05 / MEMORIES**.

## Panorama pendiente

- #583: después de este slice quedan SOUND + MEMORIES, JOURNAL + CONNECTED, Footer y los slices administrables Theme Studio/Preview.
- #398 sigue siendo la visión de producto; #583 manda sobre la fidelidad visual pública.
- No iniciar slices dependientes mientras #592 no haya cerrado exact-main + deploy.
