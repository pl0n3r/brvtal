# BRVTAL — Último deploy

<p align="center">
  <a href="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml"><img alt="BRVTAL CI" src="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg?branch=main"></a>
  <a href="https://sonarcloud.io/dashboard?id=pl0n3r_brvtal"><img alt="Sonar Quality Gate" src="https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_brvtal&metric=alert_status"></a>
  <a href="https://github.com/pl0n3r/brvtal/actions/workflows/production-deploy-observer.yml"><img alt="Deploy Observer" src="https://github.com/pl0n3r/brvtal/actions/workflows/production-deploy-observer.yml/badge.svg?branch=main"></a>
</p>

> **Development dashboard** · snapshot de **solo el deploy actual** para Issue #390.

## Progress convention

- ✅ ~~Struck through~~ = completed and verified through the required delivery gates.
- 🚧 Normal text = pending or currently in progress.

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Work line | 🚧 **#390 · WEBSITE → SEO workspace canónico** | `work/issue-390` · PR #621 |
| Base exacta | ✅ ~~main v0.1.43 validado y desplegado~~ | `722690d2fc9feeb1cfa21ead9b8f85c9555f00f5` |
| Versión | 🚧 **0.1.44 candidate** | centralized server-rendered SEO authority |
| Producción | 🚧 pendiente merge + exact-main + observación Hostinger | sin migraciones ni mutaciones manuales SEO |

## Huella del cambio

<!-- brvtal:git-delta -->

| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **27** | **+1865** | **−139** | **+1726** |

## Calidad y entrega

<!-- brvtal:gate-plan -->

| Control | Estado / contrato |
| --- | --- |
| Gates | **preflight · coordination · fast[PHP+JS] · database · chromium · real-stack · webkit** |
| PR + snapshot exacto | PR #621 · Issue #390 · UUID `74a6178a-25bf-4c36-9278-e3d1ce1f8fe1` |
| Single authority | 🚧 Home/Contact + Events/Artists/Sets/Releases/Blog/Pages usan la metadata del renderer público |
| AUTO semantics | 🚧 override vacío vuelve a fallback vivo; no materializa defaults derivados |
| Static routes | 🚧 allowlist cerrada Home/Contact dentro de `settings.seo.routes`; sin tabla nueva |
| Entity routes | 🚧 columnas SEO existentes + persistence/audit boundary compartido |
| Canonical/social | 🚧 canonical derivado/read-only; OG/Twitter heredan metadata efectiva |
| Settings | 🚧 delega metadata al workspace y conserva IndexNow |
| Durable coverage | 🚧 contratos PHP + MariaDB + browser desktop/mobile + IA/Settings |
| Sonar | ✅ ~~Quality Gate verde · 0 issues nuevos · 0 hotspots~~ |
| CodeRabbit | 🚧 full review en curso |
| CI del SHA exacto de main | 🚧 después del squash merge |

## Flujo de entrega

```mermaid
flowchart LR
 B["main v0.1.43 · 722690d"] --> S["#390 · WEBSITE → SEO"]
 S --> P["PR #621 · CI · Sonar · review"]
 P --> M["Squash merge v0.1.44"]
 M --> X["Exact-main CI"]
 X --> D["Observe Hostinger deploy"]
```

## Qué se hizo

- Un workspace **WEBSITE → SEO** dentro del shell canónico de DISCADMIN centraliza Home, Contact y seis familias editoriales.
- Home/Contact usan rutas estáticas allowlisted en el setting SEO existente; Home mantiene compatibilidad con valores legacy flat.
- Entidades reutilizan `seo_title` / `seo_description`, auditoría y defaults dinámicos ya existentes.
- AUTO / MANUAL / MIXED, filtros, health, preview efectivo y reset por campo evitan congelar fallbacks.
- Canonical es derivado; OG/Twitter reutilizan la salida server-rendered en vez de crear controles sin autoridad real.
- Settings deja de ser un segundo editor Home y conserva IndexNow como infraestructura.
- El editor usa diálogo nativo, conserva retry de saves fallidos y valida imágenes estáticas contra URLs/rutas inseguras.
- Cobertura nueva: MariaDB, contratos PHP, navegación IA, Settings, Chromium y mobile 390px.

## Archivos modificados en este deploy

- `README.md` — deploy dashboard
- `api/seo-metadata.php` — entity SEO API
- `api/seo-workspace.php` — bounded SEO inventory API
- `config/public_contact_page.php` — Contact SEO source
- `config/public_seo.php` — public metadata renderer
- `config/seo_workspace.php` — SEO registries/persistence
- `config/version.php` — v0.1.44 version
- `discadmin/admin-information-architecture.js` — SEO navigation
- `discadmin/admin-modules.js` — SEO module routing
- `discadmin/seo-workspace.css` — workspace styles
- `discadmin/seo-workspace.js` — workspace controller
- `discadmin/seo-workspace.php` — workspace fragment
- `discadmin/settings-v2.js` — Settings SEO handoff
- `docs/BRVTAL-SPEC.md` — SEO workspace contract
- `index.php` — Contact SEO render
- `package.json` — version/test suite
- `tests/blog-contract.php` — module contract
- `tests/e2e/discadmin-information-architecture.spec.mjs` — navigation coverage
- `tests/e2e/discadmin-seo-workspace.spec.mjs` — workspace browser coverage
- `tests/e2e/discadmin-settings-v2.spec.mjs` — Settings coverage
- `tests/integration/seo-workspace.php` — MariaDB SEO regression
- `tests/media-library-contract.php` — module contract
- `tests/public-contact-contract.php` — Contact SEO contract
- `tests/releases-contract.php` — module contract
- `tests/seo-contract.php` — entity SEO contract
- `tests/seo-defaults-contract.php` — fallback contract
- `tests/seo-workspace-contract.php` — workspace contract

## Validación

- Base exacta `722690d2fc9feeb1cfa21ead9b8f85c9555f00f5`: CI/validate, Sonar, Deploy Observer y Performance verdes para v0.1.43.
- PR #621 parte de esa base, 0 commits detrás y mantiene la reserva canónica de #390.
- Primer ciclo detectó dos regresiones de contrato/UI; fueron corregidas en la misma rama antes del merge.
- Pendiente: ciclo verde estable, revisión final, squash merge, exact-main y observación de producción.

## Qué sigue

| Lane | Trabajo |
| --- | --- |
| **NOW** | 🚧 [#390](https://github.com/pl0n3r/brvtal/issues/390) · cerrar PR #621 y entregar v0.1.44. |
| **NEXT** | 🚧 [#528](https://github.com/pl0n3r/brvtal/issues/528) · autosave/recovery del editor. |
| **LATER** | 🚧 [#530](https://github.com/pl0n3r/brvtal/issues/530) · recycle bin y backlog restante según [roadmap #533](https://github.com/pl0n3r/brvtal/issues/533). |
| **BLOCKED / EXTERNAL** | 🚧 migraciones o mutaciones manuales SEO en producción requieren autorización explícita. |

## Panorama general pendiente

- 🚧 **Editorial resilience:** #528 autosave/recovery y #530 recycle bin.
- 🚧 **Backlog posterior:** continuar por el orden canónico definido en Issue #533.
- 🚧 **Producción:** cualquier operación manual de datos/schema queda fuera de este deploy.
