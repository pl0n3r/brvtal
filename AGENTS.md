# BRVTAL — AI / Work single-source operating context

> **THIS FILE IS THE CANONICAL BOOTSTRAP FOR CHATGPT, WORK, CODEX OR ANY CODING AGENT.**
>
> Read **this file first**. No previous chat, saved memory, prompt history or human recap is required to understand how to continue the project. After reading it, inspect the repository state (`main`, open PRs and CI) and continue from the current codebase. Other documentation is optional deep reference for the area being changed, not a prerequisite to begin.

## 0. AI start protocol

For every new session:

1. Read `AGENTS.md` completely.
2. Inspect current `main`, open PRs and the latest **BRVTAL CI** runs.
3. If an open PR already covers the next task, continue/fix it instead of duplicating work.
4. If exact `main` CI is not green, do not open a new feature branch; finish that gate first.
5. If no PR is active, use **Current priorities** below unless the user explicitly reprioritizes.
6. Inspect implementation files and only the area-specific docs needed for the task.
7. Follow branch → implementation → tests → PR → CI → fixes → squash merge → exact-main-CI without asking routine questions.
8. If a PR materially changes product state, update this file and the corresponding README checklist/status.

### Source-of-truth precedence

1. current merged code on `main`;
2. newer merged PR decisions/tests;
3. this `AGENTS.md`;
4. `README.md`;
5. area-specific docs under `docs/`;
6. old chat history or external memory.

If code proves this file stale, correct this file in the same focused PR.

---

## 1. Product identity and infrastructure

BRVTAL is a proprietary digital platform for an underground electronic-music collective/label ecosystem. It combines a public art-directed website with a private administration application called **DISCADMIN**.

| Item | Canonical value |
|---|---|
| Production | `https://www.brvtal.com.co` |
| Admin | `https://www.brvtal.com.co/discadmin` |
| Repository | `pl0n3r/brvtal` |
| Canonical branch | `main` |
| Hosting | Hostinger shared hosting / LiteSpeed |
| Backend | PHP 8.5 |
| Database | MariaDB / MySQL-compatible |
| Public frontend | HTML + CSS + vanilla JavaScript |
| Browser testing | Playwright; Chromium + targeted WebKit |
| CI | GitHub Actions workflow **BRVTAL CI** + **PHP 8.5 Compatibility** |
| Deploy | GitHub `main` → Hostinger Git auto-deploy |
| Public language | English |

**Canonical host rule:** always use `www.brvtal.com.co` for production links, DISCADMIN links, production validation, PageSpeed/Lighthouse targets and durable documentation. Do not treat the bare `brvtal.com.co` host as the canonical project URL.

Production must remain compatible with shared hosting: no required long-running Node server, Docker runtime or SSH deploy.

Do not use FTP/manual deploy as the normal path. Source deploy and DB migration are separate operations.

---

## 2. Non-negotiable DISCADMIN architecture

DISCADMIN must always preserve:

**ONE SHELL / ONE SIDEBAR / ONE SESSION / ONE CENTRAL WORKSPACE**

Canonical route: `/discadmin`.

Rules:

- modules render inside the same shell/workspace;
- never create mini-admins, secondary sidebars or competing auth/session systems;
- mobile behavior is first-class;
- touch targets should remain about 44 px minimum where interactive;
- preserve keyboard focus/accessibility behavior;
- prefer destinations in navigation over internal implementation concepts.

### Canonical navigation / information architecture

The sidebar is intentionally organized around what the owner wants to manage, not around internal subsystems:

- **Dashboard**
- **CONTENT** — Events, Artists, Releases, Sets, Blog, Pages
- **MEDIA** — Media Library, Hero Slider
- **SITE** — Theme Studio, Settings and site-level configuration
- **SYSTEM** — Security / 2FA, System Status, Backups, Activity when exposed

Cross-cutting functions such as Global Search, Content Health, Bulk Actions, feedback and appearance should enhance the shell or relevant content screens instead of becoming competing top-level mental models.

### Content Core architectural decision

**Content Core is internal workflow infrastructure, not a user-facing top-level destination.**

Its guided Event Editor is reused by **Events**, so the owner has one obvious place to create/edit event identity, date/place, lifecycle, tickets and lineup. Its collective-membership controls are reached from **Artists → Collective Status**, not from a separate Content Core menu item.

Do not reintroduce a visible `CONTENT CORE` sidebar entry unless the user explicitly changes this decision.

Legacy routes may remain compatible internally, but the visible product vocabulary should be Events / Artists / Media / Site / System.

### Admin appearance

A fast selector lives directly in the sidebar above Logout:

- **Dark**
- **Light**
- **Glass** — translucent/iOS-inspired BRVTAL interpretation

The choice is persistent and shell-wide. It is separate from public Theme Studio.

### Admin session policy

The session is intentionally long-lived because the owner was being logged out too often:

- idle timeout: about **7 days**;
- absolute login lifetime: about **30 days**;
- persistent cookie up to 30 days;
- periodic renewal while active;
- keep `HttpOnly`, `SameSite=Strict`, CSRF and session-ID regeneration.

Do not shorten this casually.

---

## 3. Current implemented product state

Treat these as implemented foundations unless current code/tests prove otherwise.

### DISCADMIN / editorial

- canonical one-shell administration;
- simplified destination-based sidebar grouped as Content / Media / Site / System;
- responsive/mobile sidebar and record lists;
- unified forms/dialogs, validation and double-save protection;
- **Events uses the guided Content Core event workflow internally**;
- Events lifecycle, tickets and lineup/artist participation;
- Artists CRUD plus **Collective Status** sub-workflow for active/alumni/order/history;
- Sets CRUD with relations/platform links;
- Releases / label catalog;
- Blog, tags and relations;
- CMS Pages;
- Media Library + reusable picker;
- Content Health on the operational dashboard;
- SEO fields/defaults;
- Global Search (`⌘K / Ctrl+K`);
- safe Bulk Actions for allowed lifecycle/status changes;
- Admin Activity append-only audit trail;
- read-only Editorial Version History;
- System Status v2;
- Backups Foundation v1;
- Dark / Light / Glass admin appearance;
- extended admin session policy above.

### Hero / Slider Manager

The Home Hero Slider is a constrained BRVTAL-specific, LayerSlider-inspired module inside DISCADMIN. It is **not** a generic Wix/Elementor page builder.

Implemented:

- image and muted inline video slides;
- multiple slides;
- enable/disable and ordering controls;
- autoplay/interval/pause behavior;
- optional mobile-specific media;
- desktop/mobile preview;
- permanent static hero fallback when no valid published slide exists;
- visual layers: text, image, logo and CTA;
- pointer/touch drag positioning;
- entrance animation, delay and duration;
- per-layer mobile position/width/media overrides;
- hide layer on mobile;
- duplicate slide;
- reduced-motion behavior;
- deferred inactive images;
- server allowlist/sanitization;
- compatibility with older v1 fields.

Not yet implemented:

- scheduled start/end publication;
- full LayerSlider-style timeline editor;
- true drag-and-drop slide reordering if current code still uses explicit order controls.

Read `docs/HERO-SLIDER.md` when changing this module.

### Public experience

Implemented:

- responsive Home and entity delivery;
- mobile performance fallbacks for expensive effects;
- **adaptive public runtime boot:** coarse-pointer and `prefers-reduced-motion` visitors skip GSAP / ScrollTrigger / Lenis downloads entirely, while fine-pointer full-motion desktop keeps the enhanced stack;
- core public modules preserve `app → archive → media` order and continue in fallback mode if the optional motion CDN fails;
- non-blocking Google Fonts;
- mobile loader bypass and immediate hero-content improvements;
- keyboard/touch accessibility passes;
- canonical public entity pages for Events, Artists, Sets, Releases, Blog and CMS Pages;
- Event lifecycle/public archive behavior;
- Archive discovery by year/search/relationships;
- Public Media discovery;
- Related Content relationship graph;
- **CONNECTED treats Artists, Events, Sets and Releases as first-class selectable graph layers**;
- Set graph detail links through its public Artist/Event relationships and keeps canonical Set/platform links;
- Release graph detail links through public Artists and keeps canonical Release/listen links;
- Archive and Media filter state in **shareable URL query parameters**;
- Back/Forward restores discovery state while preserving hashes;
- SEO canonical/OG/Twitter/JSON-LD/sitemap/robots/404-noindex safeguards;
- optional GA4 consent foundation.

### Media Engine

Implemented foundations:

- validated uploads;
- reusable media paths/picker;
- original preservation;
- metadata/dimensions;
- WebP/context variants where supported;
- focal point;
- crop/context preview;
- resolution/quality guidance;
- reference-aware deletion protection.

### Security

Implemented contract:

- centralized admin authentication/session;
- CSRF on mutations;
- prepared statements for DB input;
- login rate limiting;
- TOTP / Google Authenticator-compatible 2FA;
- AES-256-GCM encrypted TOTP secrets;
- hashed recovery codes;
- private setting protection;
- public allowlists and draft/private filtering;
- no public stack traces/secrets;
- browser regression coverage for password → TOTP → authenticated session including WebKit/Safari behavior.

---

## 4. Recent product decisions that must not be lost

1. **DISCADMIN must feel simple to operate.** Navigation exposes destinations, not internal architecture jargon.
2. **Content Core is internal.** Events is the one visible event-management destination; Artists owns Collective Status.
3. **Dark / Light / Glass** are the three admin appearance modes and switch directly from the sidebar.
4. **Admin sessions stay long-lived** enough for daily work while keeping CSRF, Strict cookies and absolute re-login boundaries.
5. The Hero Slider should feel easy/reliable like LayerSlider, especially on mobile, but remain BRVTAL-specific.
6. Hero mobile editing supports optional mobile assets/overrides.
7. A valid static Home hero remains a permanent fallback.
8. Public Archive/Media discovery filters are shareable through URL state and respect Back/Forward.
9. `README.md` is a human technical/operations dashboard, not marketing copy.
10. Every CI run exposes useful build/deploy context via GitHub Actions Job Summary without metadata-only commits.
11. The repository itself contains enough durable context that an AI can resume without previous conversation memory.
12. **CONNECTED is a four-layer public graph:** Artists, Events, Sets and Releases remain navigable inside the graph; do not regress Sets/Releases to terminal relation links.
13. **CI optimizes for fast feedback without weakening `main`.** Pull requests use a fast syntax/contract gate plus path-aware parallel DB/Chromium/real-stack/WebKit gates; every exact `main` push runs the full matrix and ends in the stable `validate` aggregate check.
14. **Desktop motion libraries are optional enhancement, not a mobile dependency.** Do not eagerly reintroduce GSAP / ScrollTrigger / Lenis for coarse-pointer or reduced-motion public visitors; core content/runtime must remain functional without the motion CDN.
15. **Production PHP runtime is 8.5.** Keep the dedicated `PHP 8.5 Compatibility` workflow green on PRs and exact `main`; it must lint and run the PHP contract suite without PHP warnings, notices or deprecations.

---

## 5. Data / API architecture rules

- `api/public.php` is the canonical public read-only allowlist; do not create a second divergent public-data implementation.
- Protected admin APIs require authentication and CSRF as appropriate.
- Relationships are structured data, not duplicated display text.
- Drafts are first-class and may be incomplete; publication states require stronger validation.
- Public delivery filters drafts/private relations server-side.
- Media is reusable content; preserve originals and protect referenced media from unsafe deletion.
- Use transactions/row locking where multi-record integrity matters.
- Use explicit data-safe migrations for schema changes.
- **Merging source does not mean a production migration ran.**

Never run destructive production SQL, resets/seeds or irreversible data changes without explicit user approval.

---

## 6. Testing / CI / deployment contract

Historical main workflow filename:

`.github/workflows/update-release-metadata.yml`

Visible main workflow name: **BRVTAL CI**.

PHP production-runtime compatibility workflow:

`.github/workflows/php85-compatibility.yml`

Visible compatibility workflow name: **PHP 8.5 Compatibility**.

### Fast-feedback topology

The CI is intentionally layered:

- `plan` computes changed-file surfaces and which optional gates apply;
- `fast` always runs PHP syntax, JavaScript syntax and contract tests without waiting for MariaDB or browsers;
- `database` runs disposable MariaDB migrations/integration when DB/backend/admin surfaces require it;
- `browser` runs Chromium when public/admin browser-facing surfaces require it;
- `realstack` runs the authenticated PHP + MariaDB + Chromium smoke for relevant admin/API/config/database changes;
- `webkit` is a targeted Safari/WebKit TOTP regression and is path-aware on PRs;
- `validate` is the final aggregate BRVTAL CI check and must remain stable for branch-protection compatibility;
- `PHP 8.5 Compatibility / php85` explicitly provisions PHP 8.5 and rejects PHP warnings, notices and deprecations in the contract suite.

Every `push` to exact `main` and every manual workflow dispatch runs the **full BRVTAL CI matrix**; the PHP 8.5 compatibility workflow also runs on PRs and pushes to `main`. Pull requests may skip irrelevant expensive BRVTAL CI gates, but `fast` always runs. Browser/npm downloads use GitHub Actions caches where practical.

The real-stack harness accepts either the `mariadb` or preinstalled `mysql` CLI. Do not reintroduce `mariadb-client` installation merely to replace a compatible runner client.

### AI / Work execution efficiency

When an AI session changes a branch:

1. make the logical set of related edits first;
2. run/inspect the most targeted applicable checks before publishing the branch when tooling permits;
3. avoid pushing one micro-edit at a time when several edits belong to the same logical fix, because each push can cancel/restart CI;
4. once a PR exists, fix failures on that same branch and prefer one coherent update per diagnosis;
5. never trade away the full exact-`main` gate for speed.

CI covers combinations of:

- PHP 8.5 compatibility with warnings/notices/deprecations rejected;
- PHP syntax;
- JavaScript syntax;
- API/module contract tests;
- migration idempotency;
- disposable MariaDB integration;
- Playwright Chromium;
- authenticated real-stack smoke;
- targeted WebKit regressions;
- project-operations/documentation contract.

Each run publishes GitHub Actions summaries with event/ref/PR, SHA, changed files/surfaces, deploy eligibility, selected validation scope and final gate results.

### Mandatory delivery loop

1. Start a focused branch from current green `main`.
2. Implement the logical change + applicable targeted tests; batch related edits before pushing where practical.
3. Open PR to `main`.
4. Wait for the final **BRVTAL CI / validate** result and the **PHP 8.5 Compatibility / php85** result.
5. Fix failures on the same PR branch.
6. When green, squash merge.
7. Get the exact merged `main` SHA.
8. Verify full BRVTAL CI and PHP 8.5 Compatibility succeed on that exact SHA.
9. Only then begin the next branch.

Routine development operations do not require asking again.

### Status language

- **IMPLEMENTED** — code exists.
- **VALIDATED IN CODE** — CI/tests passed.
- **DEPLOYED** — Hostinger received the source.
- **VALIDATED IN PRODUCTION** — real production behavior was checked.

Never claim production verification from CI alone.

### Release metadata rule

Do **not** reintroduce automation that rewrites or commits `config/version.php`. Deployment identity is resolved at runtime through `config/deployment.php` / environment / Git checkout.

---

## 7. Protected production/security boundaries

Explicit confirmation is required before:

- destructive production SQL;
- bulk deletion of production content;
- automatic restore/revert;
- one-click restore;
- irreversible data migrations/actions;
- bypassing GitHub → Hostinger deployment.

Do not ask for confirmation for ordinary safe development steps.

Never commit credentials, tokens, passwords, private user data or production DB contents.

---

## 8. Deliberately deferred / do not implement opportunistically

Unless explicitly reprioritized:

- Bulk Delete;
- automatic one-click restore;
- automatic editorial revert;
- complex RBAC before there is a real need;
- generic Wix/Elementor-style builder;
- arbitrary per-event site builder;
- large custom analytics product;
- unnecessary i18n complexity;
- destructive production automation.

---

## 9. Current priorities

When no open PR or explicit user request exists, continue in this order after verifying code has not already completed the item:

1. **Public discovery / relationship-driven browsing** — the core CONNECTED graph already covers Artists, Events, Sets and Releases as navigable layers. Only deepen Archive/Media pathways when supported by real structured relationships; do not invent duplicated relation data just to add links.
2. **Measured public performance / responsive polish** — use real evidence/Core Web Vitals and reproducible bottlenecks. The adaptive motion-runtime boot is one completed code-level improvement; production CWV still requires real measurement evidence.
3. **Authenticated production smoke process** — safe and non-destructive; never claim it exists until actually run.
4. **Backup recovery rehearsal** — isolated/test environment only; no automatic production restore.
5. **DISCADMIN simplification/stabilization** — fix concrete friction or duplication; preserve destination-based navigation and internal Content Core architecture.
6. **Incremental Hero Slider improvements** — only when they add real editing value while preserving fallback/mobile/performance.
7. **Analytics/privacy maturity** proportional to real product needs.

An explicit user request always overrides this order.

---

## 10. Human dashboard and optional deep references

`README.md` is the human technical manual + requested-vs-completed checklist. **AI sessions should not require reading README before they can begin**.

Optional references, read only when needed:

- `README.md` — human dashboard, architecture overview, checklist and roadmap;
- `docs/BRVTAL-SPEC.md` — deeper product/architecture specification;
- `docs/DISCADMIN-UX-AUDIT.md` — responsive/admin UX debt/history;
- `docs/TESTING.md` — detailed test commands/evidence model;
- `docs/HERO-SLIDER.md` — Hero Slider contract/data model.

Current code/newer merged decisions beat stale prose.

---

## 11. Repository map

```text
.github/       GitHub Actions / CI
api/           public + protected PHP APIs
assets/        public static assets
config/        bootstrap/auth/deployment/release/media config
css/           public frontend styles
database/      base schema + explicit migrations
discadmin/     canonical DISCADMIN shell/modules/enhancements
docs/          deep technical/product references
js/            public frontend JavaScript
scripts/       support/maintenance scripts
storage/       private/runtime application storage
tests/         contracts, MariaDB integration, Playwright
uploads/       managed uploaded media in production
index.php      public router/delivery
```

---

## 12. State-maintenance contract for future AI work

The goal is that a completely new AI session can continue with **only the repository**.

Whenever a PR materially changes product state:

- update relevant current-state, decision, priority or deferred sections here;
- update the corresponding README checklist/status;
- do not write transient SHA/run numbers here as permanent state;
- keep current SHA/CI identity dynamic in Actions/System Status;
- remove completed priorities or clearly identify the next actionable continuation;
- record architectural decisions here, not only in chat;
- keep this file sufficient for the prompt **“Read AGENTS.md and continue the project autonomously”**.

If a future session needs old chat history to know what to do next, this contract has failed and repository context must be improved.
