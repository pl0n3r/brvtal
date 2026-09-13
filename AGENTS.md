# BRVTAL: operating context for future sessions

Read `README.md`, `docs/BRVTAL-SPEC.md`, `docs/DISCADMIN-UX-AUDIT.md`, and `docs/TESTING.md` before changing the product. Inspect current code, recent merged PRs, open PRs, and CI first. Current code and newer explicit decisions prevail over stale documentation; update the relevant existing document when a decision changes. Keep this file as a short entry point, not a second product specification. Never commit credentials, tokens, passwords, private user data, or production database contents.

## Product and deployment

- Production: https://brvtal.com.co. Canonical administration: https://brvtal.com.co/discadmin (`/discadmin`).
- Stack: Hostinger shared hosting, PHP 8.3, MariaDB, JavaScript, and Playwright. No long-running Node service is required in production.
- Deployment is automatic from GitHub `main` to Hostinger Git. Never use FTP or manual deployment as the routine path. Source deployment and database migration are distinct.
- DISCADMIN must preserve **ONE SHELL / ONE SIDEBAR / ONE SESSION / ONE CENTRAL WORKSPACE**. Modules change the workspace inside `/discadmin`; never add a mini-admin, second sidebar, or competing session.
- The historical workflow path `.github/workflows/update-release-metadata.yml` is the **BRVTAL CI** workflow. Preserve its filename unless a real technical need requires renaming. Do not reintroduce automation that rewrites `config/version.php` per commit. Deployment SHA is resolved by `config/deployment.php`.

## Security and production boundaries

- Use prepared statements for database input, CSRF protection for mutations, centralized admin authentication, and transactions when changes span related records. Keep secrets and stack traces out of public responses.
- Do not run destructive operations against production, production SQL, automatic restore/revert, Bulk Delete, or other irreversible changes without explicit confirmation. Do not assume source deployment applied a schema migration.
- Preserve drafts, public allowlists, original media, reference protection, and published-only public delivery; consult the specification for module-specific rules.

## Required delivery loop

1. Start a new branch from current `main`; keep the change focused.
2. Implement and run applicable syntax, contract, disposable-MariaDB integration, and Playwright checks as described in `docs/TESTING.md` and CI.
3. Open a PR and wait for **BRVTAL CI**. Fix failures on the same PR branch and rerun CI.
4. Squash merge after CI passes. Verify the **BRVTAL CI run for the exact resulting `main` SHA**.
5. Report code validation, automatic deployment, and actual production verification separately. A green CI run does not prove Hostinger deployed or that a visual production check occurred.

Routine branch, test, PR, CI, and merge steps do not require another question. Confirm only the protected production/destructive operations above. For product direction and architecture use `docs/BRVTAL-SPEC.md`; for responsive admin debt use `docs/DISCADMIN-UX-AUDIT.md`; for test commands and evidence use `docs/TESTING.md`; for current status and roadmap use `README.md`.
