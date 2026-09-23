# BRVTAL — Master Product & Technical Specification

This document is the functional and architectural source of truth for BRVTAL.

Before changing architecture, database schema, DISCADMIN, frontend, APIs, workflows or file structure, review this specification. Inspect current code and recent PRs as well: implementation and newer explicit decisions supersede obsolete descriptions here. Preserve data and working functionality, then update this specification to reflect the resolved decision. `AGENTS.md` contains the concise operating rules for future sessions.

## 1. Product identity

BRVTAL is not only an event website. It is intended to become a proprietary digital platform combining:

- underground electronic-music collective;
- event producer;
- movement/community;
- artist platform;
- label;
- content platform;
- booking platform.

Brand phrase: **“BRVTAL no es solo un colectivo, BRVTAL es música.”**

Core feeling: **OSCURO — AGRESIVO — HIPNÓTICO**.

Conceptual references include Defqon.1, Blackworks and Ourworld. Audience is broadly 25–60 and the platform must be able to operate internationally.

## 2. Visual identity

BRVTAL already has an official logo and an established flyer language. The visual system is based on black, white, metallic tones and red, with glitch, grain/noise, distortion, industrial and monospace information systems, aggressive display typography, lines, crosshairs and altered imagery.

Flyers should not be treated as conventional generic cards. Prefer masks, crops, zoom, glitch, displacement, overlays, editorial composition and controlled transitions. The result must feel recognizably BRVTAL rather than like a generic SaaS dashboard or commercial template.

## 3. Public frontend

The public site is immersive and highly visual while remaining fast, usable, responsive and accessible.

Desired techniques include:

- fixed navigation;
- fullscreen `+` menu;
- preloader and page transitions;
- GSAP / ScrollTrigger;
- Lenis;
- horizontal sections when narratively justified;
- giant typography;
- cursor and magnetic effects;
- selective WebGL/GLSL;
- reduced-motion support.

Effects must never be added merely for decoration. Mobile is a first-class requirement.

## 4. Sound

The site may use sound as part of the experience. It must expose **SOUND ON / SOUND OFF**, respect browser autoplay restrictions, and require user interaction when necessary.

## 5. Public narrative

Conceptual narrative:

Intro → Manifesto → highlighted/upcoming content → Events → Artists → Sets/Sound → Media/Archive → Contact.

This is a narrative direction, not a rigid page-builder sequence.

## 6. Language

The public frontend is currently **English only**. The architecture may remain reasonably i18n-ready, but no language selector or unnecessary translation complexity should be added now.

## 7. Initial page model

Initial pages include:

- Home;
- About;
- Manifesto;
- Events;
- Artists;
- Sets / Music;
- Releases;
- Media / Archive;
- Contact;
- Legal;
- Blog;
- individual Event;
- individual Artist;
- individual Set;
- individual Release;
- individual Blog post;
- individual CMS Page.

The architecture must allow future pages without becoming a generic site builder.

Canonical published entity routes are implemented and must remain server-rendered enough to deliver correct public metadata and `404/noindex` behavior.

## 8. Homepage direction

Homepage composition should remain relatively stable and art-directed. CMS controls may modify content and selected presentation parameters, but administrators should not be able to accidentally destroy the visual system with a generic component builder.

## 9. Events

DISCADMIN must support complete event management:

- create/edit;
- drafts;
- publish;
- archive;
- preview;
- media;
- SEO;
- statistics;
- tickets;
- artist participation / roster;
- timetable when applicable;
- history/versioning when implemented.

Lifecycle/commercial statuses include:

- draft;
- published;
- archived;
- upcoming;
- tickets_available;
- last_tickets;
- sold_out;
- cancelled;
- finished.

These may be represented by more than one field if that produces a cleaner model.

## 10. Event editor UX

Event creation should use a guided wizard rather than a single intimidating form.

Requirements:

- incomplete content can be saved;
- drafts are first-class;
- warnings generally do not block draft saves;
- preview is desirable;
- autosave may be added when safe;
- publication scheduling may be added later when explicitly required;
- version history is desired.

Default required event fields are name, date, city and description, but an incomplete **draft** may be saved before all publication-required fields exist.

The Content Core event wizard supports direct step navigation and a persistent save action. Known production UX/persistence defects discovered during smoke testing are stabilization debt and should be fixed without redesigning the canonical shell.

## 11. Artist participation / lineup

BRVTAL does not necessarily use a traditional lineup model. Events and Artists are related entities.

Use `event_artists` rather than duplicating artist names as event text. Participation may contain `lineup_order`, `role` and related metadata.

Canonical API contract:

- `GET /events/{id}/lineup`
- `POST /events/{id}/lineup`

POST requires CSRF and replaces event participation transactionally. Route parsing must support the canonical front-controller path used in production as well as compatible rewritten forms.

## 12. Artists

Artists have full CRUD and a rich profile model. Most fields should remain optional. Artists without images use a BRVTAL-consistent anonymous/incognito fallback rather than broken images.

Collective membership/lifecycle is part of the model, including `artist_collective_history` and collective status/order/joined/left fields.

## 13. Sets / Music

Music architecture begins with:

- Sets;
- Releases.

Sets may come from multiple platforms via external URLs and should use official embeds when stable. Do not limit the product to SoundCloud, although SoundCloud is an important content source. Sets can relate to artists and events.

## 14. Releases / Label

Releases are part of BRVTAL’s evolution into a label. Releases, artist credits, artwork, catalog data, platform links and draft/published/archived lifecycle are implemented as first-class content.

Do not collapse Releases back into generic pages or Sets.

## 15. Tickets

BRVTAL does not initially process full payments internally. Ticket types may direct users to external methods such as ticketing platforms, Nequi, QR or external links.

Multiple ticket types are supported. A type may include name, price, state/availability and external purchase/payment information.

Do not store purchaser/attendee personal data in this phase.

Public event data should expose active and sold-out ticket types as appropriate.

## 16. Archive

BRVTAL preserves history. Finished events and historical media must not simply disappear. Users should be able to discover prior events, artists, sets, releases and media.

Public Archive foundation is implemented. Active lifecycle content and historical lifecycle content must stay distinct, and historical events must not expose expired ticket purchase CTAs.

Archive Discovery v2 supports combined year, text and relationship filters. Historical cards link to canonical published Event routes when a valid slug is available.

## 17. Media Library

DISCADMIN media selection should be visual and as frictionless as WordPress-style media selection: searchable, reusable and easy to select. Administrators should not need to manually copy URLs for routine use.

Media may be reused by multiple pieces of content. Before deletion, references must be detected and destructive actions guarded.

The reusable Media Library, picker, metadata, reference detection, sidecars and protected deletion flow are implemented foundations.

The public Media experience supports search and image/video/audio filtering. Images open in an accessible viewer; audio and video use native browser controls and all content remains limited to published API records.

## 18. Media Engine

Principle: **one source image → multiple optimized variants**.

Current implemented foundation includes:

- preserving uploaded originals;
- image dimensions and quality/dimension warnings;
- sidecar metadata;
- WebP generation when supported;
- square thumbnail-style variants;
- larger width variants for suitable source images;
- reference-aware cleanup of originals/variants/sidecars.

Media Engine v2 extends this foundation with editorial UX:

- focal point / crop controls;
- square, card and hero crop previews;
- context-aware WebP variants;
- per-context quality/resolution guidance;
- safe regeneration that preserves originals and removes superseded derived files.

Administrators should not manually prepare many versions of the same source image. Media should remain automatically organizable by date.

## 19. Pages

Pages are managed through BRVTAL templates + an easy editor + advanced HTML for advanced users. Do not build an Elementor/Wix-style generic page builder.

Published Pages use canonical public entity delivery and SEO rules like the other public content families.

## 20. Blog

The Blog supports posts, drafts, publication/archive lifecycle, cover media, tags, SEO and relations to Events/Artists/Sets/Releases. Do not add unnecessary editorial automation.

## 21. Related content

The platform models relations such as:

Artist → Events / Sets / Releases

Event → Artists / Sets

Set → Artist / Event

Release → Artists

Public Related Content foundation is implemented and must only resolve through entities that are themselves public. Private/draft names, IDs or relationships must never leak through a published related entity.

## 22. SEO and Content Health

Support title, description, canonical, Open Graph/social previews, Google preview and structured data when appropriate.

Public delivery uses server-rendered metadata for Home and published entity routes. Canonical route families are `/events/{slug}`, `/artists/{slug}`, `/sets/{slug}`, `/releases/{slug}`, `/blog/{slug}` and `/pages/{slug}`. The public sitemap contains published entities only; unknown, draft or private entity routes return `404` with `noindex`.

Canonical entity routes render their own public experience and relationships. They must remain recognizably BRVTAL, responsive and usable with reduced motion, and must never expose draft or private related records.

Editorial SEO defaults are automatic but non-destructive:

- empty SEO title falls back to the content name/title;
- empty SEO description falls back to the relevant description/bio/excerpt/editorial text and is normalized to a search-appropriate length;
- manually authored SEO values always win.

The CMS calculates a **Content Health Score** and warns about missing SEO, missing images, incomplete information and common errors.

CMS intelligence advises; it does **not** autonomously publish or irreversibly modify editorial content.

### Entity-specific public structured data

- The JSON-LD family is built from the same canonical, published/visible row as the public page, not from separate client-side metadata. A draft artist may never reappear through `performer` or `byArtist`.
- A dated, located public Event exposes `MusicEvent`, local ISO-8601 `startDate`, genuine `Place`/city and a lifecycle-aware `eventStatus` (scheduled, completed or cancelled). SQL DATETIME fields have no timezone metadata: do not invent UTC or infer a timezone from the web server.
- A published Blog exposes its genuine publication and modification timestamps; Releases expose real release dates, catalog IDs and published linked artists; Sets expose their published linked artist and validated public listening URL; Artist identity URLs are only published when configured and valid.
- If an Event lacks a valid date or location, a Blog lacks its publication date, a Release has neither a valid release date nor a published artist, or a Set has neither a public artist nor a safe listening link, publish neutral `WebPage` JSON-LD rather than inventing rich-result properties. The HTTP/publication policy and existing canonical URL must remain unchanged.
- External entity references accept HTTP(S) URLs without embedded credentials. Only published artist relations appear in JSON-LD. No new schema migration or separate schema CMS is required.


## 23. DISCADMIN search and bulk actions

Global admin search is implemented across Events, Artists, Sets, Media, Pages, Releases and Blog with `⌘K / Ctrl+K` navigation inside the canonical shell.

Bulk status actions are implemented for Events, Artists, Sets, Pages, Releases and Blog with CSRF, explicit status allowlists, row locking, transactions, rollback on missing IDs and a maximum batch size.

**Bulk Delete is not part of v1** and must not be introduced casually. Destructive bulk operations require a separate explicit safety design.

## 24. DISCADMIN architecture

Canonical route: `/discadmin`.

DISCADMIN is one proprietary application, not a collection of independent mini-apps.

Requirements:

- one shell;
- one sidebar;
- one navigation system;
- one central workspace.

Canonical navigation currently includes:

- Dashboard;
- Events;
- Artists;
- Releases;
- Sets;
- Media;
- Pages;
- Blog;
- Content Core;
- Theme Studio;
- Settings;
- Security / 2FA;
- System Status.

Avoid unnecessary top-level menu proliferation. Cross-cutting capabilities such as Search, Bulk Actions, Content Health, SEO assistance and Activity should enhance the existing shell rather than create parallel admin applications.

## 25. Content Core

Content Core is an internal DISCADMIN module. It must not feel or behave like a standalone admin application.

Selecting Content Core keeps the shell/sidebar/session and changes only the central workspace.

Current responsibilities include:

- Event Editor;
- Collective Roster;
- ticket types;
- event lifecycle;
- event participation.

Production smoke testing has already found and resolved multiple persistence/route/UI defects. Remaining defects should be tracked as known stabilization debt; do not use them as justification for a second shell or a replacement admin architecture.

## 26. Security / 2FA

Security / 2FA is an internal DISCADMIN module sharing the same shell, sidebar, session and workspace.

TOTP is compatible with Google Authenticator / RFC 6238 and the end-to-end login flow has been validated in production, including Safari/WebKit behavior.

Current persistence foundation includes:

- `admins.totp_enabled`;
- `admins.totp_secret_enc`;
- `admins.totp_confirmed_at`;
- `admin_recovery_codes`;
- a persistent private encryption key stored in the existing `settings` table when a dedicated server key is unavailable.

The TOTP encryption setting is private: it must not be exposed through public/admin settings listings or editable/deletable through the normal Settings UI.

Security principles:

- encrypted TOTP secret;
- recovery codes hashed;
- CSRF;
- rate limiting;
- no plaintext recovery-code storage;
- no automatic 2FA disabling when login verification fails.

AES-256-GCM has been used for the encrypted secret. CI includes a dedicated browser flow for password → TOTP challenge → verified session → DISCADMIN bootstrap, including a WebKit run for the regression that affected Safari.

## 27. Administrators and activity

Multiple administrators are allowed. For now they may share the same permissions; do not add complex RBAC prematurely.

Admin Activity / History v1 is implemented as an append-only audit foundation. Important editorial/admin mutations record actor, action, resource, changed fields and safe before/after snapshots while filtering secret/security values.

Editorial Version History v1 groups those snapshots by content item in a chronological timeline and renders readable field-level before/after differences inside the existing Dashboard activity panel.

The audit and version-history UI is read-only. Do **not** add automatic restore/revert from activity history yet; future restore UX must introduce separate safeguards.

## 28. Dashboard and System Status

Dashboard must be operationally useful, not decorative.

System Status v2 is implemented inside the canonical DISCADMIN shell as a visual operations/control-room view. It centralizes:

- platform health score;
- API/database/runtime/service indicators;
- BRVTAL-managed storage utilization;
- deployment SHA/environment/runtime;
- database content counts;
- GitHub repository metrics;
- Content Health summary;
- recent admin activity;
- actionable issues;
- read-only Advanced Diagnostics.

The visible storage capacity must represent BRVTAL-managed application data against the configured operational hosting quota. Shared host-node filesystem capacity may remain available for diagnostics but must not be presented as the BRVTAL account quota.

Diagnostics may detect and recommend but should not autonomously repair production. Private logs must never expose sensitive stack traces publicly.

## 29. Settings and tracking

Site Settings centralizes global configuration.

Selected JavaScript tracking snippets may be inserted into `<head>` from Settings, but this must be tightly scoped and secure.

Public APIs must distinguish **public settings** from **private/admin settings**. Never expose secrets, internal configuration, analytics secrets, arbitrary tracking code or credentials through a public settings endpoint.

`api/public.php` is the canonical public settings allowlist implementation.

## 30. Analytics and privacy

Analytics are desired, with Google Analytics as the preferred external analytics foundation where appropriate. Avoid building a huge custom analytics product without need.

Use privacy-first data minimization. Do not collect attendee purchasing data in this phase.

Cookie handling should remain minimal and reflect the analytics/features actually deployed.

## 31. Legal

Legal structure is primarily oriented to Colombia while allowing international growth. It should support privacy, cookie and terms requirements based on actual platform behavior.

Do not automatically claim complete legal compliance. Tooling assists maintenance; it does not replace legal review where required.

## 32. Theme Studio and skins

Earlier concepts included BRVTAL CORE + event skins. The **current decision** is to keep the public design fixed for now.

Do not prioritize:

- arbitrary skin creation;
- complex live theme builders;
- one-site-per-event architectures.

BRVTAL remains visually consistent.

## 33. Backups

**Backups Foundation v1 is implemented and production-validated.**

Desired capability includes manual and later automatic backups of database/files/history with safe downloadability and operational status inside DISCADMIN.

The first implementation should favor shared-hosting-safe primitives:

- authenticated manual backup creation;
- database export without depending on SSH;
- media/file inventory and, where safe, downloadable archives;
- manifest containing creation time, deployment SHA, size/checksum and backup components;
- protected download through authenticated endpoints rather than public static URLs;
- backup history/status integrated into the existing Technical/System Status experience;
- activity logging for important backup operations.

Backups may contain sensitive database material such as encrypted security state; backup files therefore belong in private storage and must never be directly public.

Do **not** implement direct one-click restore from DISCADMIN yet. Restore is a separate sensitive operation requiring explicit safeguards and validation.

## 34. Versioning

DISCADMIN displays application version, deployment build/source and environment.

Example:

`v0.1.0 · PRODUCTION`

`DEPLOY abc1234`

Product version and deployment source are different concepts:

- `BRVTAL_APP_VERSION`: deliberately controlled product version;
- release/build metadata in `config/version.php`: intentionally changed when product/release metadata should change;
- runtime deployment identity: resolved from the actual deployed Git checkout/environment by `config/deployment.php`.

Do not increment semantic product version on every commit and do not reintroduce automatic metadata-only stamping per commit.

## 35. GitHub and deployment

Repository: `pl0n3r/brvtal`

Canonical branch: `main`

GitHub is the code/source/history/review/deployment source of truth.

Production deployment path is **GitHub main → Hostinger automatic Git deployment**.

Do not use FTP as the normal deployment path. Do not perform a manual Hostinger deploy unless explicitly requested.

Normal delivery flow:

branch → PR → CI → squash merge → `main` CI → Hostinger auto-deploy → production verification.

## 36. Hosting constraints

Current production environment is Hostinger Premium shared/managed LiteSpeed with PHP 8.3.x, MariaDB 11.8.x, no SSH, approximately 25 GB operational account storage and 512 MB memory.

Design for shared-hosting constraints and avoid unnecessary infrastructure complexity. Host filesystem totals exposed by PHP may describe a shared node and must not be treated as the account quota.

## 37. Production safety

Production is live Hostinger. Do not run destructive migrations, mass deletes, resets, destructive seeds or experimental production changes without evaluating consequences.

Staging may be added later.

## 38. Pre-deploy checks

Before a change is considered ready for production, validate as applicable:

- PHP syntax;
- JavaScript syntax;
- automated contracts/tests;
- DB/migrations;
- critical files;
- config;
- secrets;
- integrity;
- browser behavior for critical DISCADMIN/public flows.

On update failure, stop. Do not blindly continue dependent changes. Automatic rollback is not currently required.

## 39. CI and release metadata workflow

`.github/workflows/update-release-metadata.yml` is historically named but currently runs **BRVTAL CI**. It validates PHP/JavaScript, contracts, MariaDB integrations, migration idempotency and browser behavior.

It does **not** rewrite `config/version.php` on every commit.

`config/version.php` conceptually contains intentional product/release metadata such as:

- `BRVTAL_APP_VERSION`;
- `BRVTAL_APP_BUILD`;
- `BRVTAL_APP_ENV`;
- `BRVTAL_RELEASE_DATE`.

The code actually serving production is traced separately at runtime through `config/deployment.php`, which resolves the deployed source commit. Metadata-only changes are not themselves product features or new semantic versions.

## 40. Current database model

Core/current tables include, among others:

- `admins`;
- `events`;
- `artists`;
- `event_artists`;
- `sets_media`;
- `media`;
- `pages`;
- `settings`;
- `analytics_events`;
- `admin_recovery_codes`;
- `event_ticket_types`;
- `artist_collective_history`;
- `releases` and release relation/link tables;
- `blog_posts`, `blog_tags`, `blog_post_tags`, `blog_post_relations`;
- `admin_activity_log`.

This list may evolve.

Production migrations already applied include the Content Core, Releases, Blog, SEO and Admin Activity foundations. Do not re-run previously applied production migrations blindly. TOTP compatibility/key work reused existing schema/settings where possible and did not require a new production migration for the persistent encryption key.

## 41. API architecture

Public and admin APIs must remain clearly separated.

`api/public.php` is read-only and allowlisted.

`api/index.php` handles authenticated admin resources and may provide a backwards-compatible `public` route only by delegating to `api/public.php`.

Never maintain two divergent public data implementations.

Canonical event-participation route parsing must support both front-controller and compatible rewritten forms of `/events/{id}/lineup`.

Public entity delivery must filter draft/private content and private related records at the server/API layer, not merely hide them visually.

## 42. Security principles

Apply:

- prepared statements;
- CSRF;
- secure sessions;
- output escaping;
- rate limiting where appropriate;
- password hashing/verification;
- validated uploads;
- authorization;
- private logs;
- no public stack traces;
- untrusted input handling.

Never commit real DB passwords, API secrets, encryption keys or private credentials to GitHub.

## 43. Migrations

Future migrations should be explicit, reviewable, data-safe, documented and idempotent where practical. Do not silently mutate production schemas.

Source deployment and database migration are independent states. If new source requires a new production schema, validate the migration first, provide exact SQL when manual production application is required, wait for confirmation, and only then mark the feature fully deployed.

## 44. DISCADMIN UX

Priorities:

- fast routine administration;
- few clicks;
- global search;
- visual media selection;
- clear forms;
- useful warnings;
- drafts;
- consistent navigation;
- recognizably BRVTAL visual language.

Desktop is the primary admin experience, but tablet/mobile must not break.

The canonical sidebar stays visible on desktop. Do not recreate a parallel `admin-sidebar.php` architecture for Content Core or Security.

Operational/technical screens should favor useful visual indicators over walls of raw text; raw diagnostics belong behind deliberate advanced controls.

## 45. Workspace behavior

Sidebar remains persistent while modules replace the central workspace.

Examples:

- Events → Events workspace;
- Content Core → Content Core workspace;
- Security / 2FA → Security workspace;
- System Status → operations/control-room workspace.

## 46. Accessibility and performance

Maintain contrast, focus states, labels, reasonable keyboard navigation, semantic HTML and reduced-motion support.

Optimize images, scripts, animations, fonts, video, WebGL, lazy loading and caching. Avoid heavy effects on devices that do not benefit from them.

Admin enhancement assets use deployment-SHA cache busting so browsers receive the code corresponding to the deployed checkout without requiring manual hard-refresh behavior.

## 47. Content fallbacks

Incomplete content must degrade gracefully. No broken images or broken components for artists/events/releases lacking optional media or metadata.

## 48. Avoid duplication

Before creating a new table, endpoint, service, editor, media picker or settings system, check whether an existing implementation can be extended safely.

Do not rebuild existing Media Engine, Search, Activity, SEO, Archive or Related Content foundations under new parallel names.

## 49. Change traceability

Keep Git history coherent, descriptive and reasonably reversible. Avoid accidental giant commits that mix unrelated problems.

When a product decision supersedes an older decision, update this specification so contradictory requirements do not remain active.

Functional changes should normally use a dedicated branch/PR and squash merge after CI. Direct functional changes to `main` are not the normal workflow.

## 50. Definition of status

Always distinguish:

1. **IMPLEMENTED** — code exists;
2. **VALIDATED IN CODE** — syntax/tests/local/CI behavior passed;
3. **DEPLOYED** — automatic production deployment completed;
4. **VALIDATED IN PRODUCTION** — real production behavior was verified.

Never report one status as another.

## 51. Roadmap responsibility and separation

GitHub Issue **#533** is the canonical BRVTAL execution roadmap.

The roadmap contains only execution/progress information:
- phases;
- tasks and milestones;
- ✅ completed / 🚧 pending or in progress / ⛔ blocked state;
- assigned versions;
- linked Issues and PRs;
- merge SHA and validation/deployment evidence when relevant;
- blockers and their resolution;
- concise progress notes tied to actual state changes.

The roadmap does **not** store permanent reference text such as policies, conventions, manuals, agent instructions, architectural rules, durable design decisions, security/delivery doctrine or explanations of how the roadmap operates.

Responsibility is separated as follows:
- **`AGENTS.md`** — how agents/sessions work and deliver changes;
- **`docs/BRVTAL-SPEC.md`** — durable product, architecture, functional and technical decisions;
- **Issue #533** — what is planned, active, blocked or completed and in what order;
- **specific Issues** — executable scope and acceptance criteria;
- **PRs** — concrete implementation and validation evidence;
- **`README.md`** — current deploy snapshot, not cumulative roadmap.

Completed roadmap work remains visible and struck through as historical execution evidence. Fixed normative prose may be removed from the roadmap and relocated here or to `AGENTS.md` without being treated as deleted delivery history.

An explicit user reprioritization updates Issue #533. Progress must not be duplicated as a second priority list in this specification.

## 51.1 Multi-agent development coordination

BRVTAL uses GitHub-native work coordination to preserve maximum safe parallelism without allowing sessions to overwrite one another.

- An Issue is the unit of reservable implementation work.
- `work/issue-N` is the canonical branch for a reserved Issue; branch creation is the atomic reservation lock.
- Trusted reservation metadata is published by GitHub Actions and bound to a UUID session.
- Pull Requests must match their Issue, canonical branch and active reservation, and must declare a closing relationship to that Issue.
- Changed-file overlap against any other open PR targeting `main` is a fail-closed integration error and reports the exact colliding paths.
- Ready/draft/closed PR transitions and Issue close/reopen events synchronize visible coordination state and branch cleanup.
- Coordination complements the existing maximum of four independent work lines; it does not authorize parallel merges. Merges to `main` remain serialized.
- BRVTAL CI owns source validation. Coordination is one job feeding the existing stable `validate` aggregate, not a competing CI system.
- Exact-main validation, Sonar, CodeRabbit and production deployment observation retain their existing independent meanings and gates.
- Deploy-bound PR titles carry the target version as `(vX.Y.Z)`; the rule applies prospectively.
- Coordination never authorizes destructive production actions or production migrations.

## 51.2 Unsaved editor change protection

DISCADMIN editors must not discard user work silently.

- The legacy shared editor modal and the Content Core Event editor share one dirty-state manager.
- Dirty state compares the current editable/structural editor payload against a clean baseline; it covers ordinary fields plus dynamic ticket and lineup membership/order.
- Async editor hydration may refresh the clean baseline only until the administrator starts editing.
- Escape, Close and Cancel request confirmation only when the editor is dirty. Unchanged editors close immediately.
- Workspace navigation, technical-route navigation, browser history routing and Logout must also consult the same dirty-state guard before replacing an editor; rejected discard leaves the current editor and baseline intact.
- Async workspace navigation and Logout own operation tokens: stale completions cannot release or commit a newer dirty-editor transaction.
- A confirmed navigation revalidates the editor snapshot before final commit. If a later programmatic edit appears, DISCADMIN restores the previous canonical workspace/URL and preserves the dirty editor.
- If authentication expires while a dirty editor is pending, the shell preserves that editor DOM and shows an explicit session-ended state instead of rendering it away.
- Successful navigation after an accepted discard commits and retires all tracked editor baselines, including Content Core's `#eventModal`, so replacement cannot leave stale dirty state behind.
- Successful persistence explicitly marks the editor clean before closing or before allowing later close, so saving never produces a false discard warning.
- Browser/page unload retains native unsaved-change protection while a tracked dirty editor is open.
- This protection is UI safety only; it does not create autosave, drafts or server-side recovery semantics.

## 52. Final product vision

BRVTAL should not feel like “an event website with an admin panel.”

It should become **BRVTAL DIGITAL PLATFORM**:

PUBLIC EXPERIENCE + CONTENT PLATFORM + ARTIST PLATFORM + EVENT PLATFORM + MUSIC/LABEL + ARCHIVE + PROPRIETARY DISCADMIN.

All parts must share a coherent, secure, scalable and unmistakably BRVTAL architecture.


## 39. Canonical DISCADMIN data grid

Major record lists use one shared professional data-grid interaction model for Events, Artists, Releases, Sets, Media, Pages and Blog.

The canonical shared layer is `discadmin/admin-data-grid.js` + `discadmin/admin-data-grid.css`. Modules retain their own editorial actions and filters, but must not reintroduce bespoke table/card interaction models for record management.

Grid behavior:

- sortable columns use a tri-state cycle: ascending → descending → module default;
- explicit sorting is deterministic and uses record identity as a stable secondary order;
- column visibility is configurable through Columns / View and can be restored to module defaults;
- visible columns persist per authenticated administrator and per module through private `admin.grid.<admin_id>.<module>` settings;
- those private preference records are not exposed through the generic Settings editor;
- every supported grid provides row selection, select-all for the current result set and clear selection;
- modules with safe status bulk actions reuse the existing transactional Bulk Actions engine rather than implementing a second mutation path;
- destructive bulk delete remains out of scope;
- Artists, Sets, Releases and Blog keep visual ordering; drag/drop ordering is disabled whenever an explicit grid sort or partial filtered view is active;
- Releases Catalog and Blog render through the same canonical grid pattern as the core record lists;
- Media keeps its uploader and inspector around the canonical record table rather than maintaining a separate record-card interaction model.

The shared grid must remain keyboard/focus accessible and horizontally usable on narrow/mobile viewports.
