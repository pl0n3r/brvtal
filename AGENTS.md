# BRVTAL — AI / Work single-source operating context

> **THIS FILE IS THE CANONICAL BOOTSTRAP FOR CHATGPT, WORK, CODEX OR ANY CODING AGENT.**
>
> Read **this file first**. No previous chat, saved memory, prompt history or human recap is required to understand how to continue the project. After reading it, inspect the repository state (`main`, open PRs and CI) and continue from the current codebase. Other documentation is optional deep reference for the area being changed, not a prerequisite to begin.

## 0. AI start protocol

For every new session:

1. Read `AGENTS.md` completely.
2. Inspect current `main`, open PRs and the latest **BRVTAL CI** runs before choosing work.
3. If an open PR already covers the next task, continue/fix that PR instead of creating duplicate work.
4. If exact `main` CI is not green, do **not** open the next feature branch; diagnose or finish the current gate first.
5. If there is no active PR, use **Current priorities** below to choose the next meaningful task.
6. Inspect implementation files and the relevant optional deep-reference document only when needed for that task.
7. Follow the mandatory branch → tests → PR → CI → squash merge → exact-main-CI loop without asking routine questions.
8. Before finishing a feature/status-changing PR, update this file and `README.md` when the project state or checklist changed.

### Source-of-truth precedence

When information conflicts, use this order:

1. current merged code on `main`;
2. newer merged PR decisions/tests;
3. this `AGENTS.md` operating context;
4. `README.md` human technical dashboard;
5. area-specific docs under `docs/`;
6. old chat history, stale notes or external memory.

If code proves this file stale, correct `AGENTS.md` in the same focused PR. **Do not preserve stale documentation merely because it is written here.**

---

## 1. Product identity and infrastructure

BRVTAL is a proprietary digital platform for an underground electronic-music collective/label ecosystem. It includes a public art-directed website and a private administration application called **DISCADMIN**.

| Item | Canonical value |
|---|---|
| Production | `https://brvtal.com.co` |
| Admin | `https://brvtal.com.co/discadmin` |
| Repository | `pl0n3r/brvtal` |
| Canonical branch | `main` |
| Hosting | Hostinger shared hosting / LiteSpeed |
| Backend | PHP 8.3+ |
| Database | MariaDB / MySQL-compatible SQL |
| Public frontend | HTML + CSS + vanilla JavaScript |
| Browser testing | Playwright; Chromium + targeted WebKit |
| CI | GitHub Actions workflow **BRVTAL CI** |
| Deploy | GitHub `main` → Hostinger Git auto-deploy |
| Public language | English |

Production intentionally remains compatible with shared hosting: no long-running Node server, Docker runtime or SSH deployment is required.

Do **not** use FTP/manual deploy as the normal path. Source deploy and database migration are separate operations.

---

## 2. Non-negotiable DISCADMIN architecture

DISCADMIN must always preserve:

**ONE SHELL / ONE SIDEBAR / ONE SESSION / ONE CENTRAL WORKSPACE**

Canonical route: `/discadmin`.

Rules:

- modules render inside the same shell/workspace;
- never create mini-admins, secondary sidebars or competing login/session systems;
- Content Core, Media, Releases, Blog, Theme Studio, Settings, Security/2FA, System Status, Hero Slider and future modules remain inside this shell;
- mobile behavior is first-class, not a desktop afterthought;
- touch targets should remain at least ~44 px where interactive;
- preserve keyboard focus/accessibility behavior.

### Canonical navigation model

Core editorial areas include Dashboard, Events, Artists, Releases, Sets, Media, Pages, Blog and Content Core. Technical/configuration capabilities include Theme Studio, Settings, Security / 2FA and System Status. Cross-cutting functions such as Search, Content Health, Bulk Actions, Activity and Appearance enhance the same shell.

### Admin appearance

A fast appearance selector lives directly in the sidebar above Logout:

- **Dark**
- **Light**
- **Glass** — translucent/iOS-inspired BRVTAL interpretation

The choice is persistent and shell-wide. It is separate from public **Theme Studio** and must not be coupled to public-site theming.

### Admin session policy

The admin session was intentionally extended because the owner was being logged out too frequently:

- idle timeout: approximately **7 days**;
- absolute login lifetime: approximately **30 days**;
- persistent cookie up to 30 days;
- periodic renewal while actively using DISCADMIN;
- keep `HttpOnly`, `SameSite=Strict`, CSRF and session-ID regeneration protections.

Do not shorten this casually.

---

## 3. Current implemented product state

Treat the following as implemented foundations unless current code/tests show otherwise.

### DISCADMIN / editorial

- canonical one-shell administration;
- responsive/mobile sidebar and admin lists;
- unified forms/dialogs, validation and double-save protection;
- Events CRUD/lifecycle/tickets/lineup/artist participation;
- Artists CRUD;
- Sets CRUD with relations/platform links;
- Releases / label catalog;
- Blog, tags and relations;
- CMS Pages;
- Content Core guided workflows;
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

The Home Hero Slider is a constrained, BRVTAL-specific, LayerSlider-inspired module inside DISCADMIN. It is **not** a generic Wix/Elementor page builder.

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
- deferred loading for inactive images;
- server allowlist/sanitization and compatibility with older v1 slide fields.

Not yet implemented:

- scheduled start/end publication;
- full LayerSlider-style timeline editor;
- true drag-and-drop slide reordering if current code still uses explicit order controls.

The detailed data/behavior contract is in `docs/HERO-SLIDER.md` when changing this module.

### Public experience

Implemented:

- responsive Home and entity delivery;
- mobile performance fallbacks for expensive effects;
- non-blocking Google Fonts;
- mobile loader bypass and improved immediate hero content;
- keyboard/touch accessibility passes;
- canonical public entity pages for Events, Artists, Sets, Releases, Blog and CMS Pages;
- Event lifecycle/public archive behavior;
- Archive discovery by year/search/relationships;
- Public Media discovery;
- Related Content relationship graph;
- Archive and Media filter state persisted in shareable URL query parameters;
- Back/Forward restores discrete discovery state while preserving section hashes;
- SEO canonical/OG/Twitter/JSON-LD/sitemap/robots/404-noindex safeguards;
- optional GA4 analytics consent foundation.

### Media Engine

Implemented foundations include:

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

Implemented/security contract:

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

These decisions are already part of the intended product direction:

1. **DISCADMIN appearance must be easy to switch** from the sidebar, not hidden in a separate Settings page.
2. **Dark / Light / Glass** are the three admin appearance modes.
3. **Admin sessions must remain long-lived** enough for daily work while retaining security controls.
4. The **Hero Slider** should feel easy/reliable like LayerSlider, especially on mobile, but stay BRVTAL-specific rather than becoming a generic page builder.
5. Hero mobile editing supports optional mobile assets/overrides rather than forcing desktop media to fit every phone layout.
6. A valid static Home hero must remain as a permanent fallback so public Home cannot become blank because of slider configuration.
7. Public Archive/Media discovery filters should be **shareable through URL state** and respect browser Back/Forward.
8. `README.md` is a human technical/operations dashboard, not marketing copy.
9. Every CI run should expose useful build/deploy context through GitHub Actions Job Summary without metadata-only commits.
10. The repository itself must contain enough durable context that an AI can resume the project without relying on previous conversation memory.

---

## 5. Data / API architecture rules

- `api/public.php` is the canonical public read-only allowlist. Do not create a second divergent public-data implementation.
- Protected admin APIs require authentication and CSRF as appropriate.
- Relationships should be structured data, not duplicated display text.
- Drafts are first-class and may be incomplete; publication-oriented states require stronger validation.
- Public delivery must filter drafts/private relations server-side.
- Media is reusable content; preserve originals and protect referenced media from unsafe deletion.
- Use transactions and row locking where multi-record integrity matters.
- Use explicit, data-safe migrations for schema changes.
- **Merging source does not mean a production migration ran.**

Never run production SQL, destructive resets/seeds or irreversible data changes without explicit user approval.

---

## 6. Testing / CI / deployment contract

Historical workflow filename:

`.github/workflows/update-release-metadata.yml`

Visible workflow name: **BRVTAL CI**.

Do not rename the file merely because its historical filename is odd.

CI currently covers combinations of:

- PHP syntax;
- JavaScript syntax;
- API/module contract tests;
- migration idempotency;
- disposable MariaDB integration;
- Playwright browser tests;
- targeted WebKit regressions;
- project-operations/documentation contract.

Each run publishes a GitHub Actions Job Summary with useful build/deploy context such as event/ref/PR, SHA, changed files/surfaces, deploy eligibility, validation result and runtime versions.

### Mandatory delivery loop

1. Start a focused branch from current green `main`.
2. Implement the change and applicable tests.
3. Open a PR to `main`.
4. Wait for **BRVTAL CI**.
5. Fix failures on the same PR branch.
6. When CI is green, squash merge.
7. Obtain the exact merged `main` SHA.
8. Verify **BRVTAL CI succeeds on that exact SHA**.
9. Only then begin the next branch.

Routine branch/test/PR/merge operations do not require asking the user again.

### Status language

Use these terms precisely:

- **IMPLEMENTED** — code exists.
- **VALIDATED IN CODE** — CI/tests passed.
- **DEPLOYED** — Hostinger received the source.
- **VALIDATED IN PRODUCTION** — real production behavior was checked.

Never claim production verification from CI alone.

### Release metadata rule

Do **not** reintroduce automation that rewrites or commits `config/version.php` per change. Deployment identity is resolved at runtime through `config/deployment.php` / environment / Git checkout.

---

## 7. Protected production/security boundaries

Explicit confirmation is required before:

- destructive production SQL;
- bulk deletion of production content;
- automatic restore/revert;
- one-click restore;
- irreversible data migrations/actions;
- bypassing the normal GitHub → Hostinger deployment path.

Do not ask for confirmation for ordinary safe development steps.

Never commit credentials, tokens, passwords, private user data or production database contents.

---

## 8. Deliberately deferred / do not implement opportunistically

Unless the user explicitly reprioritizes them:

- Bulk Delete;
- automatic one-click restore;
- automatic editorial revert;
- complex RBAC/multi-admin permissions before there is a real need;
- generic Wix/Elementor-style builder;
- arbitrary per-event site builder;
- large custom analytics product;
- unnecessary i18n/language selector complexity;
- destructive production automation.

---

## 9. Current priorities

When there is no open PR or explicit new user request, continue in this order, validating that current code has not already completed the item:

1. **Public discovery / relationship-driven browsing** — deepen useful navigation between Events, Artists, Sets, Releases, Archive and Media rather than adding isolated pages.
2. **Measured public performance / responsive polish** — prefer real evidence/Core Web Vitals and reproducible bottlenecks over speculative micro-optimizations.
3. **Authenticated production smoke process** — document/automate only when it can be safe and non-destructive; do not claim this exists if not actually run.
4. **Backup recovery rehearsal** — safe, documented rehearsal in an isolated/test environment; no automatic production restore.
5. **Content Core stabilization** — fix concrete reproduced defects in focused PRs; do not redesign the architecture without evidence.
6. **Incremental Hero Slider improvements** — only when they add real editing value while preserving fallback, mobile usability and performance.
7. **Analytics/privacy maturity** — only proportional to real product needs.

An explicit user request always overrides this priority order.

---

## 10. Human dashboard and optional deep references

`README.md` is the human-facing technical manual + requested-vs-completed checklist. It should mirror major state changes but **AI sessions should not require reading README before they can begin**.

Optional deep references, read only when the current task needs them:

- `README.md` — human technical dashboard, architecture overview, full feature checklist and roadmap.
- `docs/BRVTAL-SPEC.md` — deeper product/architecture specification.
- `docs/DISCADMIN-UX-AUDIT.md` — responsive/admin UX debt and audit history.
- `docs/TESTING.md` — detailed test commands/evidence model.
- `docs/HERO-SLIDER.md` — Hero Slider contract/data model/behavior.

Current code and newer merged decisions always beat stale prose.

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

Therefore, whenever a PR materially changes product state:

- update the relevant **Current implemented product state**, **Recent product decisions**, **Current priorities** or deferred sections in this file;
- update the corresponding human checklist/section in `README.md`;
- do not write transient SHA/run numbers into this file as permanent state;
- keep exact current SHA/CI identity dynamic in GitHub Actions/System Status;
- remove completed items from priorities or clearly mark the next actionable continuation;
- record important architectural decisions here, not only in chat;
- keep this file useful enough that the prompt **“Read AGENTS.md and continue the project autonomously”** is sufficient to resume work.

If a future session needs old chat history to understand what to do next, this contract has failed and the repository context should be improved.
