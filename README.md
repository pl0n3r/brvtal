# BRVTAL

**RAVE TILL GRAVE**

BRVTAL is an international underground electronic-music platform and collective focused on events, artists, music, media, archive, culture and future label/merch capabilities.

## Project

This repository contains the BRVTAL public website and **DISCADMIN**, the proprietary CMS used to manage the platform.

The project is designed for long-term modular growth while keeping the public experience visually focused and the administration experience practical for non-technical users.

### Current architecture

- Public website: English-first public experience
- DISCADMIN: authenticated administration interface
- PHP 8.3+ / MariaDB
- Shared-hosting compatible architecture
- REST-style internal/public API endpoints
- Centralized admin authentication and CSRF protection
- Optional TOTP / Google Authenticator 2FA
- Content Core for events, artists, collective roster and ticket types
- Media architecture prepared for automatic image variants
- SEO, analytics, legal/privacy and infrastructure modules planned as separate stages

## Content model

The core entities are:

- **Events** — lifecycle, dates, location, description, featured state and ticket configuration
- **Artists** — profiles, social links and BRVTAL collective lifecycle
- **Collective Roster** — active/alumni participation and ordering
- **Event participation** — artists associated with events and their presentation order/role
- **Ticket types** — multiple external-payment options per event without storing purchaser data
- **Sets / Music** — external-platform music and sets related to artists/events
- **Media** — reusable visual assets
- **Pages** — controlled BRVTAL pages and SEO metadata
- **Settings** — centralized site configuration

## Security

Security is treated as a first-class requirement.

- Admin sessions use centralized authentication controls.
- State-changing requests require CSRF protection.
- Login attempts are rate limited.
- TOTP is optional and can be enabled per administrator.
- TOTP secrets are encrypted at rest when configured.
- Recovery codes are stored as password hashes.
- Runtime logs and production configuration must never be committed.
- Sensitive configuration belongs in the production environment, not in Git.

> Never commit `config/config.php`, credentials, production logs, secrets, API keys or other runtime/private data.

## Deployment

The production environment is currently intended for **manual FTP deployment to Hostinger**.

GitHub is the source of truth for:

- source history
- review
- versioning
- release/change tracking
- reproducible deployment packages

Production database migrations must be reviewed and executed explicitly. A migration existing in this repository does **not** mean it has already been applied to production.

## Development workflow

1. Work from `main` as the stable integration baseline.
2. Develop substantial changes on a feature branch when practical.
3. Validate PHP, JavaScript, migrations and security-sensitive changes.
4. Open a Pull Request for review when the change is substantial.
5. Merge into `main` only after the change is ready.
6. Build the required FTP package separately when production deployment is requested.

## Important production rule

Do not assume that a GitHub change is deployed to `brvtal.com.co`. Repository state and production state are intentionally treated as separate systems.

## Roadmap

### Current / next

- Event Wizard persistence hardening
- Event lifecycle management
- Ticket management without duplicate records
- Collective Roster management
- Artist lifecycle/history
- Event ↔ artist participation
- Full DISCADMIN navigation integration

### Planned

- BRVTAL Media Engine
- WordPress-like visual media picker
- Content Health / CMS intelligence
- Archive and history system
- Blog
- SEO engine
- Legal / Privacy / Consent Center
- Analytics integration
- Backups and infrastructure tools
- Redirect / 404 management

### Future

- Releases / Label
- Merch
- Public accounts/community features
- Internationalization
- Theme/skin expansion when the fixed BRVTAL design is mature

## Brand direction

**DARK — AGGRESSIVE — HYPNOTIC**

The public design is intentionally fixed during the current product stage. Event and artist content should adapt to the BRVTAL system rather than turning every event into an independent microsite.

## Delivery state

| Area | State | Notes |
|---|---|---|
| Foundation / security core | 🟢 Integrated in `main` | Central auth, CSRF, rate limiting and production hardening are in the repository. |
| Optional TOTP / Google Authenticator | 🟢 Implemented in branch lineage | Optional 2FA; activation remains administrator-controlled. |
| Content Core database migration | 🟡 Ready / production deployment not confirmed | `database/migration_content_core_01.sql` exists; production application must be confirmed separately. |
| Content Core API | 🟢 Integrated in `main` | Event lifecycle, ticket types, collective fields and event participation endpoints are represented in the API/model. |
| Content Core workspace | 🟢 Integrated in `main` | Event Editor / Collective Roster workspace exists at `discadmin/content-core.php`. |
| Event Wizard authentication | 🟢 Integrated in `main` | Workspace obtains the authenticated session CSRF token before state-changing requests. |
| Ticket persistence | 🟢 Integrated in `main` | Existing ticket types are updated, new ones are created, and removed rows are deleted instead of duplicating on repeated saves. |
| Event participation persistence | 🟢 Code integrated / production verification pending | API GET/POST relation endpoint exists; Content Core now has a bridge to load and save event participation. Production verification remains separate. |
| DISCADMIN shell navigation | 🟡 In progress | Protected Content Core entry point exists; visible sidebar integration is the current shell task. |
| Event Wizard CRUD | 🟡 Refinement | Core save path is hardened; full lifecycle/roster validation and production verification remain. |
| Temporary Content Core workflow | 🟢 Removed | Build-time patch workflow was removed from `main`; Content Core API is maintained as normal source code. |
| Visual Media Engine | ⚪ Pending | Visual picker, automatic variants, crop preview and usage protection are next. |
| Archive / Blog / SEO / Legal / Analytics | ⚪ Pending | Planned after Content Core and Media Engine stabilization. |

### Completion standard

A feature is not considered complete merely because code exists. BRVTAL tracks: **developed → validated → integrated → in `main` → deployed → verified in production**.

## Repository

Official source repository:

https://github.com/pl0n3r/brvtal

## Status

BRVTAL is under active development. Content Core is being hardened before the Media Engine begins. Production deployment and database migration state must be verified separately from GitHub.
