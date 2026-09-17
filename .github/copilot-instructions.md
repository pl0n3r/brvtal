# BRVTAL repository instructions for GitHub Copilot

Read `AGENTS.md` completely before planning, editing, reviewing, or testing. It is the canonical AI operating context for this repository. Current merged code and newer merged decisions override stale prose.

## Working rules

- Keep changes focused. Do not mix unrelated product work into one PR.
- Reuse existing architecture before creating new modules, APIs, tables, or parallel implementations.
- DISCADMIN must preserve **ONE SHELL / ONE SIDEBAR / ONE SESSION / ONE CENTRAL WORKSPACE** at `/discadmin`.
- `api/public.php` is the canonical public read-only allowlist. Do not create a second public-data implementation.
- Treat relationships as structured data. Never infer cultural relationships from titles, dates, filenames, proximity, or visual similarity.
- Preserve drafts/private filtering server-side and never expose secrets, stack traces, credentials, or private data.
- Shared-hosting compatibility is mandatory: PHP + MariaDB + vanilla frontend, with no required long-running Node/Docker runtime.
- Public mobile, keyboard accessibility, reduced motion, and performance are first-class requirements.
- Coarse-pointer and `prefers-reduced-motion` visitors must not regain mandatory GSAP / ScrollTrigger / Lenis downloads.
- Interactive touch targets should remain about 44 px minimum where practical.
- Prefer targeted regression tests first, then the broader applicable suite.
- Do not weaken tests merely to make CI pass.
- Do not run destructive production SQL, resets, restores, bulk deletes, manual deploys, or irreversible migrations.

## Delivery contract

For deploy-bound work follow the repository loop in `AGENTS.md`: focused branch → implementation → applicable tests → fresh `README.md` deploy snapshot → PR → **BRVTAL CI / validate** → fixes → squash merge → exact-main CI.

`README.md` is transient and must describe only the current deploy: exact modified files, concise summary, validation state, and next action. Durable architecture belongs in `AGENTS.md` or area-specific docs.

Use status language precisely:

- **IMPLEMENTED** — code exists.
- **VALIDATED IN CODE** — tests/CI passed.
- **DEPLOYED** — Hostinger received the source.
- **VALIDATED IN PRODUCTION** — real production behavior was checked.

Never claim production validation from CI alone.
