# BRVTAL — Master Product & Technical Specification

This document is the functional and architectural source of truth for BRVTAL.

Before changing architecture, database schema, DISCADMIN, frontend, APIs, workflows or file structure, review this specification. When implementation and specification conflict, preserve data and working functionality, but treat this document as the intended product direction unless a newer explicit decision supersedes it.

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
- individual Release.

The architecture must allow future pages without becoming a generic site builder.

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

Default required event fields are name, date, city and description.

## 11. Artist participation / lineup

BRVTAL does not necessarily use a traditional lineup model. Events and Artists are related entities.

Use `event_artists` rather than duplicating artist names as event text. Participation may contain `lineup_order`, `role` and related metadata.

Canonical API contract:

- `GET /events/{id}/lineup`
- `POST /events/{id}/lineup`

POST requires CSRF and replaces event participation transactionally.

## 12. Artists

Artists have full CRUD and a rich profile model. Most fields should remain optional. Artists without images use a BRVTAL-consistent anonymous/incognito fallback rather than broken images.

Collective membership/lifecycle is part of the model, including `artist_collective_history` and collective status/order/joined/left fields.

## 13. Sets / Music

Music architecture begins with:

- Sets;
- Releases.

Sets may come from multiple platforms via external URLs and should use official embeds when stable. Do not limit the product to SoundCloud, although SoundCloud is an important content source. Sets can relate to artists and events.

## 14. Releases / Label

Releases are part of BRVTAL’s evolution into a label. The architecture must support releases without requiring a future rewrite of the whole platform.

## 15. Tickets

BRVTAL does not initially process full payments internally. Ticket types may direct users to external methods such as ticketing platforms, Nequi, QR or external links.

Multiple ticket types are supported. A type may include name, price, state/availability and external purchase/payment information.

Do not store purchaser/attendee personal data in this phase.

Public event data should expose active and sold-out ticket types as appropriate.

## 16. Archive

BRVTAL preserves history. Finished events and historical media must not simply disappear. Users should be able to discover prior events, artists, sets, releases and media.

## 17. Media Library

DISCADMIN media selection should be visual and as frictionless as WordPress-style media selection: searchable, reusable and easy to select. Administrators should not need to manually copy URLs for routine use.

Media may be reused by multiple pieces of content. Before deletion, references must be detected and destructive actions guarded.

## 18. Media Engine

Principle: **one source image → multiple optimized variants**.

The Media Engine should eventually:

- preserve originals;
- generate variants;
- offer crop previews;
- adapt images by context;
- warn on poor dimensions/quality;
- provide context-appropriate fallbacks.

Administrators should not manually prepare many versions of the same source image.

Media should be automatically organizable by date.

## 19. Pages

Pages are managed through BRVTAL templates + an easy editor + advanced HTML for advanced users. Do not build an Elementor/Wix-style generic page builder.

## 20. Blog

The blog supports posts, drafts, publication, media, SEO, useful taxonomy and relations to other content. Do not add unnecessary editorial automation.

## 21. Related content

The platform should model relations such as:

Artist → Sets → Events → Releases → Media

Event → Artists → Media → related Sets

These relations can drive discovery on the public frontend.

## 22. SEO and Content Health

Support title, description, canonical, Open Graph/social previews, Google preview and structured data when appropriate.

The CMS may calculate a **Content Health Score** and warn about missing SEO, missing images, incomplete information and common errors.

CMS intelligence advises; it does **not** autonomously publish or irreversibly modify editorial content.

## 23. DISCADMIN search and bulk actions

Provide fast global admin search across events, artists, media, pages, sets, releases, blog and future content.

Bulk actions should exist where useful, with safeguards for destructive operations.

## 24. DISCADMIN architecture

Canonical route: `/discadmin`.

DISCADMIN is one proprietary application, not a collection of independent mini-apps.

Requirements:

- one shell;
- one sidebar;
- one navigation system;
- one central workspace.

Conceptual navigation currently includes:

- Dashboard;
- Events;
- Artists;
- Sets;
- Media;
- Pages;
- Content Core;
- Theme Studio;
- Settings;
- Security / 2FA;
- System Status.

Avoid unnecessary top-level menu proliferation.

## 25. Content Core

Content Core is an internal DISCADMIN module. It must not feel or behave like a standalone admin application.

Selecting Content Core keeps the shell/sidebar/session and changes only the central workspace.

Current responsibilities include:

- Event Editor;
- Collective Roster;
- ticket types;
- event lifecycle;
- event participation.

## 26. Security / 2FA

Security / 2FA is also an internal DISCADMIN module sharing the same shell, sidebar, session and workspace.

TOTP is compatible with Google Authenticator / RFC 6238.

Current persistence foundation includes:

- `admins.totp_enabled`;
- `admins.totp_secret_enc`;
- `admins.totp_confirmed_at`;
- `admin_recovery_codes`.

Security principles:

- encrypted TOTP secret;
- recovery codes hashed;
- CSRF;
- rate limiting;
- no plaintext recovery-code storage.

AES-256-GCM has been used for the encrypted secret.

## 27. Administrators and activity

Multiple administrators are allowed. For now they may share the same permissions; do not add complex RBAC prematurely.

Important administrative changes should be traceable through activity/history logs.

## 28. Dashboard and System Status

Dashboard must be operationally useful, not decorative.

System Status belongs inside DISCADMIN and should centralize version/build/environment, service health, diagnostics and detected issues.

Diagnostics may detect and recommend but should not autonomously repair production.

Private logs are desirable. Do not expose sensitive stack traces publicly.

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

Desired backup capabilities include manual and automatic backups of database/files/history and downloadability.

Do not implement direct one-click restore from DISCADMIN yet; restore is a sensitive operation.

## 34. Versioning

DISCADMIN displays application version, deployment build and environment.

Example:

`v0.1.0 · PRODUCTION`

`BUILD abc1234`

Product version and deployment build are different concepts:

- `BRVTAL_APP_VERSION`: deliberately controlled product version;
- `BRVTAL_APP_BUILD`: deployment/source commit identifier.

Do not increment semantic product version on every commit.

## 35. GitHub and deployment

Repository: `pl0n3r/brvtal`

Canonical branch: `main`

GitHub is the code/source/history/review/deployment source of truth.

Production deployment path is **GitHub main → Hostinger automatic Git deployment**.

Do not use FTP as the normal deployment path. Do not perform a manual Hostinger deploy unless explicitly requested.

## 36. Hosting constraints

Current production environment is Hostinger Premium shared/managed LiteSpeed with PHP 8.3.x, MariaDB 11.8.x, no SSH, approximately 25 GB storage and 512 MB memory.

Design for shared-hosting constraints and avoid unnecessary infrastructure complexity.

## 37. Production safety

Production is live Hostinger. Do not run destructive migrations, mass deletes, resets, destructive seeds or experimental production changes without evaluating consequences.

Staging may be added later.

## 38. Pre-deploy checks

Before a change is considered ready for production, validate as applicable:

- PHP syntax;
- JavaScript syntax;
- automated contract/tests;
- DB/migrations;
- critical files;
- config;
- secrets;
- integrity.

On update failure, stop. Do not blindly continue dependent changes. Automatic rollback is not currently required.

## 39. Release metadata workflow

`.github/workflows/update-release-metadata.yml` validates PHP/JavaScript/tests and maintains `config/version.php` metadata.

`config/version.php` conceptually contains:

- `BRVTAL_APP_VERSION`;
- `BRVTAL_APP_BUILD`;
- `BRVTAL_APP_ENV`;
- `BRVTAL_RELEASE_DATE`.

The source build should correspond to the functional source commit. Metadata-only commits are not themselves new product builds.

## 40. Current database model

Known tables include:

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
- `artist_collective_history`.

This list may evolve.

The Content Core migration has already been executed in production. Do not re-run it blindly.

## 41. API architecture

Public and admin APIs must remain clearly separated.

`api/public.php` is read-only and allowlisted.

`api/index.php` handles authenticated admin resources and may provide a backwards-compatible `public` route only by delegating to `api/public.php`.

Never maintain two divergent public data implementations.

Canonical event-participation route parsing must support both front-controller and rewritten forms of `/events/{id}/lineup`.

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

## 45. Workspace behavior

Sidebar remains persistent while modules replace the central workspace.

Examples:

- Events → Events workspace;
- Content Core → Content Core workspace;
- Security / 2FA → Security workspace.

## 46. Accessibility and performance

Maintain contrast, focus states, labels, reasonable keyboard navigation, semantic HTML and reduced-motion support.

Optimize images, scripts, animations, fonts, video, WebGL, lazy loading and caching. Avoid heavy effects on devices that do not benefit from them.

## 47. Content fallbacks

Incomplete content must degrade gracefully. No broken images or broken components for artists/events/releases lacking optional media or metadata.

## 48. Avoid duplication

Before creating a new table, endpoint, service, editor, media picker or settings system, check whether an existing implementation can be extended safely.

## 49. Change traceability

Keep Git history coherent, descriptive and reasonably reversible. Avoid accidental giant commits that mix unrelated problems.

When a product decision supersedes an older decision, update this specification so contradictory requirements do not remain active.

## 50. Definition of status

Always distinguish:

1. **IMPLEMENTED** — code exists;
2. **VALIDATED IN CODE** — syntax/tests/local behavior passed;
3. **DEPLOYED** — automatic production deployment completed;
4. **VALIDATED IN PRODUCTION** — real production behavior was verified.

Never report one status as another.

## 51. Immediate stabilization priorities

Current stabilization order:

1. ensure `/events/{id}/lineup` routes correctly;
2. ensure all public settings are allowlisted and public API logic is unified;
3. run automated contract checks;
4. keep Content Core and Security / 2FA inside the canonical shell;
5. verify 2FA end-to-end in production when authenticated access is available;
6. verify Content Core persistence end-to-end in production;
7. keep this specification updated;
8. then continue larger modules such as richer Media Library, Releases, Blog and SEO.

## 52. Final product vision

BRVTAL should not feel like “an event website with an admin panel.”

It should become **BRVTAL DIGITAL PLATFORM**:

PUBLIC EXPERIENCE + CONTENT PLATFORM + ARTIST PLATFORM + EVENT PLATFORM + MUSIC/LABEL + ARCHIVE + PROPRIETARY DISCADMIN.

All parts must share a coherent, secure, scalable and unmistakably BRVTAL architecture.
