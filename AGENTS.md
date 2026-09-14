# BRVTAL — AI / Work single-source operating context

> **THIS FILE IS THE CANONICAL BOOTSTRAP FOR CHATGPT, WORK, CODEX OR ANY CODING AGENT.**
>
> Read **this file first**. No previous chat, saved memory, prompt history or human recap is required to understand how to continue the project. After reading it, inspect repository state (`main`, open PRs and CI) and continue from the current codebase.

## 0. AI start protocol

For every new session:

1. Read `AGENTS.md` completely.
2. Inspect current `main`, open PRs and latest **BRVTAL CI** runs.
3. If an open PR covers the next task, continue/fix it instead of duplicating work.
4. If exact `main` CI is not green, finish that gate before opening a new feature branch.
5. If no PR is active, use **Current priorities** unless the user explicitly reprioritizes.
6. Inspect implementation files and only the area-specific docs needed for the task.
7. Follow branch → implementation → tests → PR → CI → fixes → squash merge → exact-main-CI without asking routine questions.
8. **Every deploy-bound PR must replace `README.md` with a fresh snapshot of that deploy**: files modified, concise summary of what changed, validation state and what comes next. Do not append history. If the PR scope changes before merge, refresh README again.
9. If a PR materially changes durable product state or architecture, update the relevant sections in this file as well.

### Source-of-truth precedence

1. current merged code on `main`;
2. newer merged PR decisions/tests;
3. this `AGENTS.md`;
4. area-specific docs under `docs/`;
5. `README.md` only for the most recent deploy snapshot;
6. old chat history or external memory.

If code proves this file stale, correct it in the same focused PR.

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
| CI | **BRVTAL CI** + **PHP 8.5 Compatibility** |
| Deploy | GitHub `main` → Hostinger Git auto-deploy |
| Public language | English |

**Canonical host rule:** always use `www.brvtal.com.co` for production links, DISCADMIN links, production validation, PageSpeed/Lighthouse targets and durable documentation. Do not treat bare `brvtal.com.co` as canonical.

Production must remain compatible with shared hosting: no required long-running Node server, Docker runtime or SSH deploy. Do not use FTP/manual deploy as the normal path. Source deploy and DB migration are separate operations.

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

### Canonical navigation

- **Dashboard**
- **CONTENT** — Events, Artists, Releases, Sets, Blog, Pages
- **MEDIA** — Media Library, Hero Slider
- **SITE** — Theme Studio, Settings
- **SYSTEM** — Security / 2FA, System Status, Backups, Activity when exposed

Cross-cutting functions such as Global Search, Content Health, Bulk Actions, feedback and appearance enhance the shell or relevant screens instead of becoming competing top-level mental models.

### Content Core decision

**Content Core is internal workflow infrastructure, not a user-facing top-level destination.**

Events is the one visible event-management destination. Artists owns **Collective Status**. Do not reintroduce a visible `CONTENT CORE` sidebar entry unless the user explicitly changes this decision.

### Admin appearance

The direct sidebar selector offers **Dark / Light / Glass**. The choice is persistent and shell-wide and is separate from public Theme Studio.

### Admin session policy

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
- destination-based sidebar grouped as Content / Media / Site / System;
- responsive/mobile sidebar and record lists;
- unified forms/dialogs, validation and double-save protection;
- Events uses the guided Content Core event workflow internally;
- Events lifecycle, tickets and lineup/artist participation;
- Artists CRUD plus Collective Status;
- Sets CRUD with relations/platform links;
- Releases / label catalog;
- Blog, tags and relations;
- CMS Pages;
- Media Library + reusable picker;
- Content Health;
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
- autoplay/interval/pause;
- optional mobile-specific media;
- desktop/mobile preview;
- permanent static hero fallback;
- text/image/logo/CTA layers;
- pointer/touch drag positioning;
- entrance animation, delay and duration;
- per-layer mobile position/width/media overrides;
- hide-on-mobile;
- duplicate slide;
- reduced-motion behavior;
- deferred inactive images;
- server allowlist/sanitization;
- compatibility with older v1 fields.

Not yet implemented: scheduled start/end publication, a full LayerSlider-style timeline editor, and true drag-and-drop slide ordering if current code still uses explicit controls.

Read `docs/HERO-SLIDER.md` when changing this module.

### Public experience

Implemented:

- responsive Home and entity delivery;
- mobile performance fallbacks for expensive effects;
- adaptive public runtime boot: coarse-pointer and `prefers-reduced-motion` visitors skip GSAP / ScrollTrigger / Lenis downloads entirely; fine-pointer full-motion desktop keeps the enhanced stack;
- core runtime preserves `app → archive → media` order and survives optional motion-CDN failure;
- non-blocking Google Fonts;
- keyboard/touch accessibility passes;
- canonical public entity pages for Events, Artists, Sets, Releases, Blog and CMS Pages;
- Event lifecycle/public archive behavior;
- Archive discovery by year/search/relationships;
- Public Media discovery;
- Related Content relationship graph;
- **CONNECTED treats Artists, Events, Sets and Releases as first-class selectable graph layers**;
- Set graph detail links through public Artist/Event relationships and keeps canonical Set/platform links;
- Release graph detail links through public Artists and keeps canonical Release/listen links;
- Archive and Media filter state in **shareable URL query parameters**;
- Back/Forward restores discovery state while preserving hashes;
- SEO canonical/OG/Twitter/JSON-LD/sitemap/robots/404-noindex safeguards;
- optional GA4 consent foundation.

### Media Engine

Validated uploads, reusable paths/picker, original preservation, metadata/dimensions, WebP/context variants where supported, focal point, crop/context preview, resolution/quality guidance and reference-aware deletion protection.

### Security

Centralized auth/session, CSRF on mutations, prepared statements, login rate limiting, TOTP/2FA, AES-256-GCM encrypted TOTP secrets, hashed recovery codes, private setting protection, public allowlists/draft filtering, no public secrets/stack traces, and browser regression coverage including targeted WebKit.

---

## 4. Recent product decisions that must not be lost

1. **DISCADMIN must feel simple to operate.** Navigation exposes destinations, not internal architecture jargon.
2. **Content Core is internal.** Events is the one visible event-management destination; Artists owns Collective Status.
3. **Dark / Light / Glass** are the three admin appearance modes.
4. **Admin sessions stay long-lived** enough for daily work while keeping CSRF, Strict cookies and absolute re-login boundaries.
5. Hero Slider should feel easy/reliable like LayerSlider, especially on mobile, but remain BRVTAL-specific.
6. Hero mobile editing supports optional mobile assets/overrides.
7. A valid static Home hero remains a permanent fallback.
8. Public Archive/Media discovery filters are shareable through URL state and respect Back/Forward.
9. **`README.md` is only the latest deploy snapshot.** It must not accumulate architecture, old checklists or development history.
10. Every CI run exposes useful build/deploy context via GitHub Actions Job Summary without metadata-only commits.
11. The repository contains enough durable context for AI to resume without previous conversation memory.
12. **CONNECTED is a four-layer public graph:** Artists, Events, Sets and Releases remain navigable inside the graph.
13. **CI optimizes for fast feedback without weakening `main`.** Pull requests use path-aware gates; every exact `main` push runs the full matrix and ends in the stable `validate` aggregate check.
14. **Desktop motion libraries are optional enhancement, not a mobile dependency.** Do not eagerly reintroduce GSAP / ScrollTrigger / Lenis for coarse-pointer or reduced-motion public visitors.
15. **Production PHP runtime is 8.5.** Keep `PHP 8.5 Compatibility` green without warnings, notices or deprecations.
16. **Canonical production origin is `https://www.brvtal.com.co`.** Bare-host requests must converge on it.

---

## 5. Data / API architecture rules

- `api/public.php` is the canonical public read-only allowlist; do not create a second divergent public-data implementation.
- Protected admin APIs require authentication and CSRF as appropriate.
- Relationships are structured data, not duplicated display text.
- Drafts are first-class and may be incomplete; publication requires stronger validation.
- Public delivery filters drafts/private relations server-side.
- Media is reusable content; preserve originals and protect referenced media from unsafe deletion.
- Use transactions/row locking where multi-record integrity matters.
- Use explicit data-safe migrations for schema changes.
- **Merging source does not mean a production migration ran.**

Never run destructive production SQL, resets/seeds or irreversible data changes without explicit user approval.

---

## 6. Testing / CI / deployment contract

Main workflow file: `.github/workflows/update-release-metadata.yml`  
Visible workflow name: **BRVTAL CI**.

PHP compatibility workflow: `.github/workflows/php85-compatibility.yml`  
Visible workflow name: **PHP 8.5 Compatibility**.

### Fast-feedback topology

- `plan` computes changed-file surfaces and optional gates;
- `fast` always runs PHP/JS syntax and contracts;
- `database` runs disposable MariaDB validation when relevant;
- `browser` runs Chromium when relevant;
- `realstack` runs authenticated PHP + MariaDB + Chromium smoke for relevant surfaces;
- `webkit` is a targeted Safari/WebKit TOTP regression;
- `validate` aggregates all required BRVTAL CI gates;
- `php85` explicitly validates the production PHP 8.5 runtime.

Every push to exact `main` and manual workflow dispatch runs the full BRVTAL CI matrix. Pull requests may skip irrelevant expensive gates, but `fast` always runs.

### README per-deploy contract

For every deploy-bound PR:

- replace `README.md` completely; do not append a changelog;
- list **only the files modified in that deploy** plus a short explanation of each;
- include a concise **Qué se hizo** summary;
- include current validation status without claiming production validation from CI;
- include **Qué sigue** with the next actionable work;
- keep durable architecture/product history in `AGENTS.md` or the relevant `docs/` file;
- if a CI fix or late edit changes the PR file set or scope, refresh README before merge.

The README is intentionally transient. It should remain compact and useful during active development.

### Mandatory delivery loop

1. Start a focused branch from current green `main`.
2. Implement the logical change + applicable tests.
3. Refresh `README.md` with the exact deploy snapshot.
4. Open PR to `main`.
5. Wait for **BRVTAL CI / validate** and **PHP 8.5 Compatibility / php85**.
6. Fix failures on the same branch; refresh README again if scope/file set changed.
7. When green, squash merge.
8. Get the exact merged `main` SHA.
9. Verify full BRVTAL CI and PHP 8.5 Compatibility succeed on that exact SHA.
10. Only then begin the next branch.

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

Do not ask for confirmation for ordinary safe development steps. Never commit credentials, tokens, passwords, private user data or production DB contents.

---

## 8. Deliberately deferred / do not implement opportunistically

Unless explicitly reprioritized:

- Bulk Delete;
- automatic one-click restore;
- automatic editorial revert;
- complex RBAC before real need;
- generic Wix/Elementor-style builder;
- arbitrary per-event site builder;
- large custom analytics product;
- unnecessary i18n complexity;
- destructive production automation.

---

## 9. Current priorities

When no open PR or explicit user request exists, continue in this order after verifying code has not already completed the item:

1. **Measured public performance / responsive polish** — immediately fix known cache-busting inconsistency for `js/related-content.js` and `css/related-content.css`, then remeasure real waterfall/Core Web Vitals on canonical `https://www.brvtal.com.co`. Do not optimize source images blindly when CDN/device delivery already performs well.
2. **Public discovery / relationship-driven browsing** — deepen Archive/Media pathways only when supported by real structured relationships; do not invent duplicated relation data.
3. **Authenticated production smoke process** — safe and non-destructive; never claim it exists until actually run.
4. **Backup recovery rehearsal** — isolated/test environment only; no automatic production restore.
5. **DISCADMIN simplification/stabilization** — fix concrete friction or duplication while preserving destination-based navigation and internal Content Core architecture.
6. **Incremental Hero Slider improvements** — only when they add real editing value while preserving fallback/mobile/performance.
7. **Analytics/privacy maturity** proportional to real product needs.

An explicit user request always overrides this order.

---

## 10. Human handoff and optional deep references

`README.md` is the **latest deploy handoff only**. It is not a technical manual, architecture source, feature checklist or durable roadmap. **AI sessions should not require reading README before they can begin**.

Optional references, read only when needed:

- `README.md` — most recent deploy: changed files, summary, validation state, next action;
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

Whenever a deploy-bound PR is prepared:

- rewrite `README.md` as the current deploy snapshot, never as cumulative history;
- make its file list match the actual PR scope;
- summarize what changed and what follows next;
- update durable current-state, decision, priority or deferred sections here only when product state actually changes;
- do not write transient SHA/run numbers here as permanent state;
- keep current SHA/CI identity dynamic in Actions/System Status;
- record architectural decisions here, not only in chat;
- keep this file sufficient for the prompt **“Read AGENTS.md and continue the project autonomously”**.

If a future session needs old chat history to know what to do next, this contract has failed and repository context must be improved.
