# BRVTAL — Último deploy

> **SRE production recovery #631:** v0.1.47 exact main `786780eddd60a14c7fae4286db20459a2af8667e` is deployed and BRVTAL CI/observer passed. Authenticated production smoke #35932847504 reaches Events but observes two temporarily visible search fields before the Content Core host finishes hiding. This PR stabilizes the read-only smoke around the canonical mounted editor lifecycle without mutating production or loosening the one-search requirement.

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Work line | 🚧 #631 · production Events workspace readiness | `work/issue-631` reservation `d3db0a43-99f8-474f-87ce-7ed9aa96dd2f` |
| Base exacta | ✅ ~~main v0.1.47~~ | `786780eddd60a14c7fae4286db20459a2af8667e` |
| Versión | ✅ ~~v0.1.47 sin incremento~~ | exclusively tests + README |
| CI exact-main base | ✅ ~~success~~ | #35932632845 |
| Deploy Observer base | ✅ ~~success~~ | #35932632897 |
| Authenticated production smoke base | ⛔ failure | #35932847504 · duplicate transient search before editor settles |
| Entrega candidata | 🚧 PR gates, merge, fresh smoke | Never infer PRODUCTION GREEN from source tests |

## Huella del cambio

<!-- brvtal:git-delta -->
| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **2** | **+0** | **−0** | **0** |

## Calidad y entrega

<!-- brvtal:gate-plan -->
| Control | Estado / contrato |
| --- | --- |
| Gates | **preflight · coordination · fast[JS] · chromium** |
| Sonar / CodeRabbit | 🚧 review stable head |
| Exact-main CI/observer | 🚧 after squash merge |
| Production | 🚧 health 200 + SHA/version/DB, home/Admin/dashboard timing, native Events EDIT/date, Sets, repeated Hero |

## Qué se hizo

- Wait for the real mounted Events Content Core host and its internal workflow `.wrap` to become hidden before asserting the one visible native Events search; fail on duplicate search rather than choosing `.first()` or hiding the assertion.
- Verify native grid's visible search `[data-admin-grid-search]` and preserve real record EDIT, API/grid/editor date checks, Sets relations, repeated Hero and production read-only browser guard.
- Preserve v0.1.47 runtime/frontend and data exactly; no migration, credentials or content writes.

## Archivos modificados

- `README.md` · latest exact delivery snapshot.
- `tests/e2e/production-authenticated-smoke.mjs` · wait for true Events editor readiness and ensure one visible search.

## Validación

- Base exact-main CI/observer both passed; production smoke #35932847504 failed only before the Event edit assertion because Playwright strict locator matched two visible search inputs in a transitional state.
- Final PR gates and executed post-merge production smoke still required. Do not close incident before five proven production GREEN signals.

## Qué sigue

- 🚧 **NOW**: [#631](https://github.com/pl0n3r/brvtal/issues/631) complete authenticated smoke through Events, Sets and Hero.
- 🚧 **NEXT**: [#533](https://github.com/pl0n3r/brvtal/issues/533) record PRODUCTION GREEN only after all five conditions.
- 🚧 **LATER**: product backlog deferred until GREEN.
- 🚧 **BLOCKED / EXTERNAL**: await actual production verification; no credentials or customer data needed in PR.

## Panorama general pendiente

- 🚧 **NOW**: production GREEN recovery only.
- 🚧 **NEXT**: close incident with exact production evidence.
- 🚧 **LATER**: remaining roadmap after GREEN.
- 🚧 **BLOCKED / EXTERNAL**: production authenticated smoke still failing.
