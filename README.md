# BRVTAL

**RAVE TILL GRAVE**

BRVTAL is an international underground electronic-music platform and collective focused on events, artists, music, media, archive and future label/merch capabilities.

## Current architecture

- Public website: English-first public experience
- DISCADMIN: authenticated administration interface
- PHP 8.3+ / MariaDB
- Shared-hosting compatible architecture
- REST-style API
- Centralized admin authentication and CSRF protection
- Optional TOTP / Google Authenticator 2FA
- Content Core for events, artists, collective roster and ticket types
- Media architecture prepared for automatic image variants

## Content Core

Events support lifecycle states, dates, locations, descriptions, featured state, ticket configuration and archive metadata. Artists support BRVTAL collective lifecycle. Event participation is stored independently in `event_artists`; it does not redefine an artist's collective history. Ticket types support external destinations and payment instructions without storing purchaser/attendee data.

## Security

- Centralized admin sessions and CSRF protection
- Login rate limiting
- Optional TOTP with encrypted secrets and recovery-code hashes
- Production configuration and runtime data must never be committed

## Deployment

Production is intended for manual FTP deployment to Hostinger. GitHub is the source of truth for source history, review, versioning and release/change tracking. A repository migration does **not** mean the production database has received it.

## Roadmap

### Current

- Event Wizard CRUD hardening
- Event lifecycle
- Ticket persistence
- Event ↔ artist participation persistence
- Collective Roster / artist lifecycle
- DISCADMIN shell navigation integration

### Next

- Visual Media Engine
- WordPress-like visual media picker
- Automatic image variants and crop preview
- Media usage protection / reusable assets

### Planned

- Content Health / CMS intelligence
- Archive and history
- Blog
- SEO engine
- Legal / Privacy / Consent Center
- Analytics integration
- Backups / infrastructure tools
- Redirect / 404 management

### Future

- Releases / Label
- Merch
- Public accounts/community
- Internationalization
- Theme/skin expansion after the fixed BRVTAL design matures

## Delivery state

| Area | State | Notes |
|---|---|---|
| Foundation / security core | 🟢 Integrated in `main` | Central auth, CSRF, rate limiting and production hardening. |
| Optional TOTP / Google Authenticator | 🟢 Implemented | Activation remains administrator-controlled. |
| Content Core database migration | 🟡 Ready / production deployment not confirmed | `database/migration_content_core_01.sql`. |
| Content Core API | 🟢 Integrated in `main` | Lifecycle, ticket types, collective fields and lineup endpoint. |
| Content Core workspace | 🟢 Integrated in `main` | `discadmin/content-core.php`. |
| Ticket persistence | 🟢 Integrated in `main` | Existing rows update, new rows create, removed rows delete. |
| Event participation persistence | 🟢 Integrated in `main` | Wizard now loads and saves event roster through `/events/{id}/lineup`. |
| Temporary Content Core workflow | 🟢 Removed | No build-time patch workflow remains. |
| DISCADMIN shell navigation | 🟡 Pending | Protected Content Core entry exists; visible sidebar wiring remains. |
| Event Wizard CRUD | 🟡 Refinement | Production verification and deeper validation remain. |
| Visual Media Engine | ⚪ Pending | Next major implementation stage. |
| Archive / Blog / SEO / Legal / Analytics | ⚪ Pending | Later stages. |

### Completion standard

A feature is not complete merely because code exists. BRVTAL tracks: **developed → validated → integrated → in `main` → deployed → verified in production**.

## Status

BRVTAL is under active development. Content Core participation persistence is now integrated in `main`; production deployment and database migration state remain separate and must be verified explicitly.