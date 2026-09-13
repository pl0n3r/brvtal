# BRVTAL product and DISCADMIN audit — September 2026

This is a source and workflow audit of the current repository, checked against `README.md` and `docs/BRVTAL-SPEC.md`. It is not a claim that every authenticated production flow has been manually exercised with real content on every device.

## Product map

BRVTAL now has a public discovery/editorial surface, structured entities (events, artists, sets, releases, media, pages and blog), Content Core workflows, Theme Studio, Security / 2FA, system diagnostics, backups, activity/history, and consent-gated GA4 integration. The product direction is coherent: one platform, reusable media, explicit publication, and one DISCADMIN shell. The next quality gain comes from making routine work faster and clearer across these existing capabilities.

## Prioritized findings

| Priority | Finding and evidence | Recommendation |
| --- | --- | --- |
| P0 | The mobile sidebar used to take up to 45% of the viewport above every workspace (`discadmin/admin-modules.css`), while the base shell stacked it above content (`discadmin/index-core.php`). | Use the existing sidebar as a keyboard-accessible mobile drawer, leaving the workspace first. **Addressed in this change.** |
| P0 | Legacy listing CSS hides columns 2–4 below 850px (`discadmin/index-core.php`). Editors can lose date, location, status, relationship or type context when reviewing a row. | Replace generic compressed tables with module-specific responsive cards that preserve each record's key fields and actions. Validate with realistic content lengths at 320–850px. |
| P1 | Common navigation, form labels, helper text and actions use 8–11px text and narrow tap targets (`discadmin/index-core.php`, `discadmin/admin-modules.css`). | Establish a shared admin type/spacing/control scale, touch targets, visible focus, and predictable section hierarchy. **First shared pass addressed in this change**; individual modules still need a focused pass. |
| P1 | Generated form labels lacked `for` connections to controls (`discadmin/index-core.php`). | Connect labels to fields and progressively add meaningful validation, error summaries, keyboard/focus behavior and accessible dialog semantics. **Label association addressed in this change.** |
| P1 | The admin shell and many workflows are concentrated in large inline PHP/JS/CSS blocks (`discadmin/index-core.php`), while extensions override one another in extra stylesheets. | Incrementally extract shared shell, form and table components with visual regression coverage. Avoid a full rewrite; keep one canonical state and sidebar. |
| P1 | The roadmap explicitly notes remaining Content Core production smoke debt (`README.md`, Known stabilization debt). | Run authenticated smoke checks with real content and record which create/edit/publish relationships pass or fail before adding more editorial features. |
| P2 | Backup v1 includes manual creation and download, but restore is intentionally deferred (`README.md`, Backups Foundation). | Document and rehearse a recovery procedure with a disposable database and private files. Treat restore automation as a separate guarded project. |
| P2 | Public discovery and responsive/performance polish remain the active roadmap priority (`README.md`, Current roadmap). | Measure actual mobile journeys and Core Web Vitals on event, artist, release and archive pages; fix the highest-friction journeys before expanding page types. |
| P2 | GA4 only runs when configured and consented (`README.md`, Analytics/privacy foundation). | Configure the production measurement ID when ready, confirm consent transitions and event data in GA4, and keep reporting there rather than duplicating analytics in DISCADMIN. |

## UX direction

Use a calm, consistent admin shell: stable navigation on desktop, a single drawer on small screens, clear page titles, persistent context, readable status and feedback, and primary actions near the content they affect. Favor informative record cards on phones, simple forms with explicit save/publish states, and media previews where visual selection matters. Colors are secondary to legibility, predictable interaction and recoverability.

## Suggested next sequence

1. Redesign legacy Events, Artists, Sets, Media and Pages lists as responsive cards without hiding data; validate tablet and phone sizes.
2. Standardize dialogs/forms across old and new modules, including focus, validation, save feedback, and draft/publish clarity.
3. Test Content Core end to end against production-like data and close the remaining stabilization debt.
4. Run a measured public mobile/performance/accessibility pass, then address the highest-impact issues.
5. Rehearse backup recovery and verify GA4 configuration/consent in production.
