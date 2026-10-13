# BRVTAL public-home partial ownership

This is a structural-only split of `index.html`; PHP `index.php` assembles a **fixed allowlist** of ten partials before applying the existing CMS, i18n, accessibility and SEO transformations. The compiled template must reproduce `tests/fixtures/home-partials-984-baseline.html` exactly. No visual or copy change is authorized by #984.

| Partial | Responsible Concept 05 leaf | Current baseline content |
| --- | --- | --- |
| `nav.html` | #974 | Header, locale selector and overlay navigation |
| `hero.html` | #968 | Hero and logo |
| `experience.html` | #970 | Existing Manifesto and Genesis sections, unmodified |
| `nights.html` | #971 | Existing Events and archive |
| `artists.html` | #971 | Artists roster |
| `sound.html` | #972 | Sets/Sound |
| `memories.html` | #972 | Media archive |
| `journal.html` | #973 | Existing Transmissions editorial section |
| `connected.html` | #973 | Intentionally empty: no Connected block exists in pre-refactor HTML; future work must add it explicitly |
| `footer.html` | #974 | Existing Footer |

`index.html` and the fixed loader in `index.php` remain the #969 composition surface after #984 is merged. The ten leaf-owned partial paths are disjoint. Future changes to shared PHP composition must claim that surface separately rather than editing another agent's lease.

Only the PHP-rendered public entrypoint is authoritative. Direct static rendering of `index.html` is not the website: unresolved markers require the PHP composition step. Missing/duplicate markers or unreadable partials return HTTP 503 and never expose filesystem details or silently publish an incomplete home.

**Production gate:** #739 health incident and #993 exact-main version/CI incident remain independent; this refactor is build-ahead, no automatic deployment or production claim.
