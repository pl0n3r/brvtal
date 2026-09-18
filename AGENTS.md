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
8. **Every deploy-bound PR must replace `README.md` with a fresh snapshot of that deploy**: files modified, concise summary of what changed, validation state, what comes next, and an updated **general panorama of meaningful work still pending across BRVTAL**. The panorama is mandatory and must not be limited to the immediate next task. Do not append deploy history. If the PR scope changes before merge, refresh README again.
9. If a PR materially changes durable product state or architecture, update the relevant sections in this file as well.
10. **Prefer the real E2E test user for authenticated validation.** When a DISCADMIN flow can be exercised through the isolated real-stack Playwright environment, validate it with the disposable E2E admin user and real PHP/MariaDB stack rather than relying only on mocked browser harnesses. Keep focused mocks when they provide useful isolation, but use the E2E user for as much realistic authenticated coverage as reasonably possible. Never use production credentials, production data, destructive production operations or production migrations for E2E validation.

### Parallel execution rule

Parallelization is the **default operating mode**, not an optional optimization.

- **Parallelize everything that is safely independent** when it reduces lead time: analysis, preflight, source inspection, test preparation, gate review and separate implementation lines may proceed concurrently.
- Before every tool batch, identify independent operations. If **2 or more read-only operations** are independent, batch them concurrently instead of issuing them one by one. For GitHub connector orchestration, prefer `Promise.all(...)` for independent reads.
- Gate review should fan out by default: read **PR state + exact-head CI/check-runs + combined statuses + Sonar relay/comments + CodeRabbit comments/reviews/threads** concurrently when those sources are independent.
- While CI, Sonar, CodeRabbit or another external gate is running, use available time for **read-only preflight or analysis of the next independent block** instead of idling.
- Use up to **4 concurrent work lines** when they are genuinely independent and unlikely to overlap files, mutable state or review scope.
- Treat serial execution of independent reads as an exception: there should be a real dependency, rate-limit/tool constraint or shared-state reason.
- **Merges to `main` are always serialized.** Before every merge, re-read the exact current `main` SHA, the PR head SHA and all applicable gates; if `main` moved, recontrast the branch against the new base before merging.
- Same-file write sequences, dependent branches and shared mutable state remain serialized to avoid conflicting or stale edits.
- Do not open the next dependent implementation branch before the previous merge has passed exact-`main` validation; read-only analysis for that next block may still proceed in parallel.
- Production migrations, destructive production operations and other protected actions are never parallelized or run automatically.

### Source-of-truth precedence

1. current merged code on `main`;
2. newer merged PR decisions/tests;
3. this `AGENTS.md`;
4. area-specific docs under `docs/`;
5. `README.md` only for the most recent deploy snapshot and current pending-work panorama;
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
| CI | **BRVTAL CI** (single automatic code-validation workflow) |
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
- **MEDIA** — Media Library, Memories, Hero Slider
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
- Artists CRUD plus Collective Status with canonical lifecycle validation and transactional `artist_collective_history` periods;
- Sets CRUD with relations/platform links;
- Releases / label catalog;
- Blog, tags and relations;
- CMS Pages;
- Media Library + reusable picker;
- Memories curation from existing Media Library image/video/audio assets with public title, optional context, ordering and draft/published state; removing curation keeps the source asset;
- Content Health;
- SEO fields/defaults;
- Global Search (`⌘K / Ctrl+K`);
- safe Bulk Actions for allowed lifecycle/status changes;
- Admin Activity append-only audit trail;
- read-only Editorial Version History;
- System Status v2 with operational health and cached public GitHub backlog visibility;
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
- core runtime preserves `app → roster → sets → transmissions → archive → media` order and survives optional motion-CDN failure;
- non-blocking Google Fonts;
- keyboard/touch accessibility passes;
- canonical public entity pages for Events, Artists, Sets, Releases, Blog and CMS Pages;
- Event lifecycle/public archive behavior;
- Home Artists is a connected **BRVTAL Roster**: real `active` and `alumni` membership states come from Collective Status, non-members remain Artists/collaborators, and canonical navigation stays on `/artists/{slug}`;
- canonical Artist pages expose real membership facts and structured published Events/Sets/Releases/Transmissions without inferring genres or Memories;
- Home Sets is a connected **BRVTAL listening library**: published Sets remain in canonical API order, filter by real public Artist/Event relations, navigate primarily to `/sets/{slug}`, preserve explicit external LISTEN actions, expose honest empty/failure states and never infer genre metadata;
- Home **TRANSMISSIONS** is the editorial surface for published Blog records: it reuses the shared public payload, preserves canonical Blog ordering/navigation, and resolves only sanitized explicit Event/Artist/Set/Release relation IDs against the final public entity pools, including archived Events; empty and unavailable states remain distinct;
- Archive discovery by year/search/relationships;
- Public Media discovery;
- Home Memories is an explicit curated gallery backed by `memories` records rather than the whole Media Library; only published Memories with published image/video/audio sources are exposed, with editorial ordering, two-column mobile rhythm and an immersive keyboard-accessible viewer;
- Related Content relationship graph;
- **CONNECTED treats Artists, Events, Sets and Releases as first-class selectable graph layers**;
- Set graph detail links through public Artist/Event relationships and keeps canonical Set/platform links;
- Release graph detail links through public Artists and keeps canonical Release/listen links;
- Archive, Media and CONNECTED graph selection state in **shareable URL query parameters**;
- Back/Forward restores discovery state while preserving hashes;
- exact-deploy Production Performance evidence with modern Chromium mobile/desktop metrics, LCP breakdown and resource waterfall diagnostics;
- SEO canonical/OG/Twitter/JSON-LD/sitemap/robots/404-noindex safeguards;
- Google Tag Manager as the sole public tag-delivery layer, loaded automatically on public pages; direct GA4 loading is retired and GA4/pixels/other optional tags are configured inside GTM.

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
8. Public Archive/Media filters and CONNECTED graph selection are shareable through URL state and respect Back/Forward.
9. **`README.md` is the latest deploy snapshot plus the current pending-work panorama.** It must not accumulate deploy history, architecture prose or stale checklists, but every deploy must keep a concise overview of meaningful work still pending across the project.
10. Every CI run exposes useful build/deploy context via GitHub Actions Job Summary without metadata-only commits.
11. The repository contains enough durable context for AI to resume without previous conversation memory.
12. **CONNECTED is a four-layer public graph:** Artists, Events, Sets and Releases remain navigable inside the graph.
13. **CI optimizes for minimum lead time without weakening the applicable gates.** Pull requests and exact `main` pushes use the same changed-file-aware gate selection; `fast` always runs; manual dispatch runs the full matrix; every run ends in the stable `validate` aggregate check.
14. **Desktop motion libraries are optional enhancement, not a mobile dependency.** Do not eagerly reintroduce GSAP / ScrollTrigger / Lenis for coarse-pointer or reduced-motion public visitors.
15. **Production PHP runtime is 8.5.** PHP 8.5 lint/compatibility and all top-level PHP contracts run inside the always-on `fast` job; do not reintroduce a duplicate compatibility workflow.
16. **Canonical production origin is `https://www.brvtal.com.co`.** Bare-host requests must converge on it.
17. **First-party text/static delivery is deploy-versioned and cache-aware.** Keep text compression enabled, keep versioned first-party CSS/JS on long immutable caching, and do not trade cache correctness for synthetic-score shortcuts.
18. **Static Home artwork uses measured desktop WebP derivatives without sacrificing the mobile CDN path.** Preserve the original JPEGs as fallback/source assets; desktop may use responsive WebP derivatives selected from measured candidates, while mobile keeps the original URL so Hostinger/hcdn can continue its stronger device-specific optimization.
19. **Modern production performance evidence is continuous, not a one-off optimization target.** Production Performance records exact-deploy mobile/desktop metrics and resource-waterfall evidence; do not recompress or restructure assets without a measured regression, dominant bottleneck or visual justification.
20. **Specialized CI safety checks should be jobs, not duplicate workflows, when they are part of normal source validation.** README snapshot validation, PHP compatibility, production-smoke source contracts and isolated recovery rehearsal are consolidated into `BRVTAL CI`; actual production smokes remain explicit manual workflows.
21. **Artist Collective Status is lifecycle data, not free-form metadata.** Any mutation that touches membership status/dates must submit the complete lifecycle state; `none` has no membership dates, `active` requires a join date and no leave date, and `alumni` requires ordered join/leave dates. Successful Artist mutations synchronize `artist_collective_history` atomically with Admin Activity so future public Roster/history surfaces can rely on structured periods instead of inferred chronology.
22. **Public Roster semantics come from real Artist lifecycle data.** `active` and `alumni` are the only collective-membership states exposed as such; an Artist with no membership is displayed contextually as a collaborator/network Artist, never persisted as a fake membership tier. The public Roster links to canonical Artist pages, does not treat bio text as genre metadata, and does not infer Memories or other relationships that are not structurally modeled.
23. **System Status separates operational health from development backlog.** `ATTENTION REQUIRED` may surface both platform signals and open GitHub Issues, but GitHub backlog items are read-only development metadata and never lower the platform health score. A GitHub/cache outage must render backlog as unavailable/stale, never as a fake zero or a global “no active issues” claim. The integration stays server-side, anonymous/public, bounded and cached for shared hosting.
24. **Public Sets discovery is relationship-driven, not taxonomy-invented.** The listening library uses only published Set records plus public Artist/Event relations already modeled by `sets_media`; the API sanitizes those relations against final public pools before delivery. Set titles lead to canonical `/sets/{slug}` pages, external platforms remain secondary LISTEN actions, no genre is inferred from title/description, and a valid empty API result is distinct from a data/runtime failure.
25. **Google Tag Manager is the single public tag-delivery layer.** DISCADMIN stores only a validated `GTM-...` container ID; direct GA4 loading and raw executable analytics snippets are not part of the primary model. GTM loads automatically on every public page with analytics storage granted at bootstrap; BRVTAL keeps advertising storage, advertising user data and advertising personalization denied. Legacy Analytics acceptance state is not a runtime gate.
26. **Every deploy README must preserve situational awareness.** Besides the exact deploy snapshot, it must include an updated project-wide pending-work panorama so a human or new AI session can see what remains without reconstructing the backlog from chat history. The panorama is not a changelog and should be revised as work is completed or reprioritized.
27. **Memories is curation, not storage and not inferred relationships.** Media Library remains the canonical asset store. DISCADMIN `MEDIA → Memories` selects existing image/video/audio assets into explicit `memories` records with public title, optional context, order and draft/published state. Removing a Memory never deletes its source Media asset. Home Memories renders only this curated published collection; V1 does not infer Event/Artist/Set/Release relationships from filenames, dates or copy.
28. **Memory relationships belong to curated Memories, never raw Media assets.** Explicit Event/Artist/Set/Release links are stored from `memory_id`, sanitized against the final public entity pools, and may feed canonical entity pages/CONNECTED. An asset that has not been curated into Memories must never become cultural context merely because it exists in Media Library.
29. **TRANSMISSIONS is public framing over Blog, not a second CMS.** Home consumes the existing published `blog` payload through `window.BRVTALPublicDataPromise`; Blog relation rows remain singular `event` / `artist` / `set` / `release` references by `related_id`, are sanitized server-side, and are resolved client-side only against final public entity pools before rendering canonical links.
30. **Sonar baseline management is risk-first and Clean-as-You-Code.** Umbrella #451 completed the documented P0/P1/P2 security, reliability and high-maintainability burn-down. Residual MEDIUM/LOW mechanical findings (line length, naming/style, multiple statements and similar debt) are not a reason for a repo-wide churn PR: keep PR Sonar at zero new actionable issues, fix residual debt opportunistically or in bounded behavior-preserving slices, and open/reopen a focused risk issue if Sonar surfaces a new security/reliability or materially high-impact finding.

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

### Fast-feedback topology

- `fast` is the only always-on runner: it computes changed-file scope, publishes build/deploy context, enforces PHP 8.5 compatibility, runs all top-level PHP contracts, checks JavaScript syntax and validates the README deploy snapshot on PRs;
- `database` runs disposable MariaDB validation only when relevant;
- `browser` runs Chromium only when relevant;
- `realstack` runs authenticated PHP + MariaDB + Chromium smoke only for server/runtime surfaces that need it;
- `webkit` is the targeted Safari/WebKit TOTP regression and is selected only by auth/TOTP-sensitive changes;
- `recovery` runs the isolated backup recovery rehearsal only for backup/recovery surfaces or a full manual dispatch;
- `validate` aggregates every required or intentionally skipped BRVTAL CI gate into one stable final check.

Pull requests and exact `main` pushes use the same diff-aware gate selection. `fast` always runs. `workflow_dispatch` intentionally runs the complete matrix. This keeps exact-main verification intact without rerunning unrelated expensive jobs after every squash merge.

SonarQube Cloud annotations are relayed by `.github/workflows/sonar-annotation-relay.yml` into a stable PR comment after the external `SonarCloud Code Analysis` check completes. Use that relay comment as the canonical connector-readable source for Sonar rule, file, line and message details; do not guess findings from the aggregate count when annotations are unavailable directly.

Standalone automatic workflows for PHP 8.5 compatibility, README deploy snapshots, recovery rehearsal and production-smoke source contracts are deliberately retired. Their checks live inside `BRVTAL CI`, avoiding duplicate runner setup and queue contention. The authenticated/read-only production smoke and controlled Page-write smoke remain separate **manual-only** workflows because they interact with real production.

### README per-deploy contract

For every deploy-bound PR:

- replace `README.md` completely; do not append a changelog;
- list **only the files modified in that deploy** plus a short explanation of each;
- include a concise **Qué se hizo** summary;
- include current validation status without claiming production validation from CI;
- include **Qué sigue** with the next actionable work;
- include a concise, updated **Panorama general pendiente** covering meaningful work still open across BRVTAL, not only the next task;
- remove or revise panorama items as they are completed, invalidated or reprioritized so the overview does not become stale;
- keep durable architecture/product history in `AGENTS.md` or the relevant `docs/` file;
- if a CI fix or late edit changes the PR file set or scope, refresh README before merge.

The deploy README must also stay **visually scannable**, not prose-only:

- keep the BRVTAL identity visible near the top using a stable repository-native asset when available; the current preferred mark is `assets/brvtal-logo-640.webp`;
- keep the BRVTAL CI badge visible;
- include an exact `## Estado del deploy` section with a compact status table headed `Señal | Estado | Evidencia`;
- include an exact `## Flujo de entrega` section with a Mermaid diagram that shows PR snapshot, parallel gates, squash merge and exact-`main` validation;
- preserve the canonical headings `## Qué se hizo`, `## Archivos modificados en este deploy`, `## Validación`, `## Qué sigue` and `## Panorama general pendiente`;
- prefer compact tables, badges, symbols and diagrams when they improve scanning, but keep the README under the CI size limit and avoid decorative noise.

The README is intentionally transient. It should remain compact and useful during active development; the pending-work panorama is a current operational view, not cumulative history. BRVTAL CI enforces the required deploy headings, exact changed-file list and the minimum visual markers so the format does not silently regress.

### Mandatory delivery loop

1. Start a focused branch from current green `main`.
2. Implement the logical change + applicable tests.
3. Refresh `README.md` with the exact deploy snapshot and updated pending-work panorama.
4. Open PR to `main`.
5. Wait for **BRVTAL CI / validate**.
6. Fix failures on the same branch; refresh README again if scope/file set changed.
7. When green, squash merge.
8. Get the exact merged `main` SHA.
9. Verify **BRVTAL CI / validate** succeeds on that exact SHA with the path-aware gates selected for that merge.
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

1. **Public cultural archive / relationship-driven browsing** — continue #398 using real structured relationships; with Roster, Sets and curated Memories established, deepen archive/event pathways and connect Memories to other entities only after adding explicit structured relations rather than heuristics.
2. **Authenticated production smoke process** — run the existing safe workflows when authorized credentials/environment access are available; never infer production validation from CI.
3. **DISCADMIN simplification/stabilization** — fix concrete friction or duplication while preserving destination-based navigation and internal Content Core architecture.
4. **Incremental Hero Slider improvements** — only when they add real editing value while preserving fallback/mobile/performance.
5. **Analytics/privacy maturity** proportional to real product needs.
6. **Performance/recovery maintenance** — keep exact-deploy modern Chromium evidence and isolated backup recovery rehearsal green; re-optimize/rework only when measured evidence changes, and never enable automatic production restore.

An explicit user request always overrides this order.

---

## 10. Human handoff and optional deep references

`README.md` is the **latest deploy handoff plus current pending-work panorama**. It is not a technical manual, architecture source or cumulative changelog. **AI sessions should not require reading README before they can begin**, but humans and continuation sessions may use its panorama for current situational awareness.

Optional references, read only when needed:

- `README.md` — most recent deploy: changed files, summary, validation state, next action and project-wide pending-work panorama;
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

- rewrite `README.md` as the current deploy snapshot, never as cumulative deploy history;
- make its file list match the actual PR scope;
- summarize what changed and what follows next;
- refresh the **Panorama general pendiente** so it still reflects meaningful open work across BRVTAL;
- update durable current-state, decision, priority or deferred sections here only when product state actually changes;
- do not write transient SHA/run numbers here as permanent state;
- keep current SHA/CI identity dynamic in Actions/System Status;
- record architectural decisions here, not only in chat;
- keep this file sufficient for the prompt **“Read AGENTS.md and continue the project autonomously”**.

If a future session needs old chat history to know what to do next, this contract has failed and repository context must be improved.