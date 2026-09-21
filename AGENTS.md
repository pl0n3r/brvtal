# BRVTAL — Agent Operating Contract

> **CANONICAL BOOTSTRAP FOR CHATGPT, CODEX OR ANY CODING AGENT.**
>
> Read this file first. A new session must be able to continue BRVTAL from repository state alone; no previous chat, saved memory, prompt history or human recap is required.
>
> This file answers **how an agent must work**. It is intentionally not a product manual, roadmap, changelog or implemented-state inventory.

## 0. Source ownership

Use the smallest canonical source that owns the question:

1. **Current merged code/tests on `main`** — executable truth.
2. **Newer merged PR decisions/tests** — newer executable intent.
3. **`AGENTS.md`** — agent operating contract.
4. **`docs/BRVTAL-SPEC.md`** — durable product, architecture and functional rules.
5. **The specific GitHub Issue** — acceptance criteria for that work item.
6. **GitHub Issue #533** — cross-project execution order and progress only.
7. **`README.md`** — current PR/deploy snapshot and short pending-work panorama only.
8. Old chat/history — last resort, never required for normal continuation.

If code proves prose stale, correct the owning source in the same focused work line when safe.

### Ownership rule

- If a statement answers **“how should the agent execute?”**, it belongs here.
- If it answers **“how should BRVTAL behave or be designed?”**, it belongs in `docs/BRVTAL-SPEC.md`.
- If it answers **“what work is next / done / blocked?”**, it belongs in Issue #533 or the specific Issue.
- If it answers **“what changed in this PR/deploy?”**, it belongs in `README.md`.

Do not rebuild large product-state inventories or historical decision ledgers inside this file.

## 1. Start / resume protocol

For every new session:

1. Read `AGENTS.md` completely.
2. Read exact current `main` SHA.
3. Read open PRs targeting `main` and their changed files.
4. Read the latest exact-`main` **BRVTAL CI / validate** state.
5. If an open PR already owns the next work, continue/fix it instead of duplicating it.
6. If exact `main` CI is not green, finish that gate before starting a dependent feature branch.
7. If no active work owns the next task, read Issue #533 and the specific Issue acceptance criteria.
8. Reserve implementation work through the repository coordinator before writing code.
9. Inspect only the implementation files and area-specific docs needed for the task.
10. Continue through implementation → tests → PR → gates → fixes → squash merge → exact-main validation without routine approval prompts.

Use direct repository/GitHub tooling available to the agent. No ChatGPT Work workspace or previous conversation context is required.

## 2. Autonomy and maximum work per turn

The default is **autonomous execution to the largest safe, useful stopping point**.

- One user instruction to start/continue authorizes routine development steps required to advance that objective.
- Do not stop merely because one file changed, one commit landed, a PR opened, CI started, one check completed, a review appeared or a small sub-step finished.
- A progress update is not itself a reason to return control.
- Before ending a work turn, ask internally: **“Is there still a useful, safe, authorized and compatible action I can execute now?”** If yes, execute it first.
- When an external gate is running, use the time for compatible analysis, evidence gathering or preparation instead of idling.
- Investigate → fix → test → revalidate routine technical failures without asking for confirmation.
- Prefer grouped, substantial delivery over repeated “done / continue?” loops.

Routine safe authorization includes:

- branch/reservation handling;
- implementation and refactors within accepted scope;
- tests and test fixes;
- PR creation/update;
- CI/CodeRabbit/Sonar fixes;
- README snapshot refreshes;
- squash merge when all required gates and branch/current-main checks allow it;
- exact-main validation;
- non-destructive deployment observation;
- read-only production validation explicitly supported by the repository.

Stop and return control mainly when:

- a real product decision cannot be inferred from code/spec/Issue;
- required credentials or permissions are missing;
- the next action is destructive/irreversible in production;
- production data or a production migration would be changed;
- a protected external system requires human action;
- all immediate useful compatible work is exhausted.

### Communication

Prioritize execution over narration.

- Avoid micro-updates.
- Report consolidated milestones, meaningful failures, real blockers and exact validation state.
- Distinguish code validation, deploy observation and production validation.
- Never claim work is finished merely because CI started or one reviewer is processing.

## 3. Parallel execution

Parallelization is the default whenever operations are independent and safe.

- Batch independent read-only GitHub/repository operations rather than serializing them.
- Fan out PR state, CI/checks, Sonar and CodeRabbit inspection when independent.
- Use up to **4 concurrent work lines** when Issues/files/state do not overlap.
- While CI/review/deploy observation runs, advance independent read-only or separately reserved work.
- Batch related multi-file writes into one logical Git tree/commit/push when possible; avoid commit storms that restart CI/review repeatedly.
- Same-file writes, shared mutable state, dependent branches and merges remain serialized.
- **Merges to `main` are always serialized.**
- Before merge, re-read current `main`, PR head and required gates. If `main` moved, recontrast before merging.
- Do not start a dependent implementation branch before the prerequisite merge passes exact-main validation. Read-only preparation is allowed.
- Never parallelize destructive production work or production migrations.

## 4. Multi-agent work coordination

GitHub is the arbiter for concurrent implementation.

- Reserve an Issue with `/take`.
- The atomic lock is the canonical branch `work/issue-N`.
- Read the trusted `brvtal-work-reservation` marker and put its UUID in the PR body as `<!-- brvtal-reservation-id: UUID -->`.
- Visible states are `status: available`, `status: reserved`, `status: in review`, `status: completed`, `status: cancelled` and `status: blocked`.
- Use `/release UUID` for normal release, `/transfer UUID` for same-owner session transfer, and `/force-release` only for owner recovery.
- Coordinated PRs target `main`, use their reserved `work/issue-N` branch and close the matching Issue with `Closes #N`, `Fixes #N` or `Resolves #N`.
- Coordination fails closed when Issue, branch, reservation metadata or closing relation disagree.
- Changed-file overlap with another open PR targeting `main` is a fail-closed collision and must identify exact paths.
- `README.md` is the one deliberate non-blocking overlap because every PR owns an exact transient snapshot; all other overlapping paths still block. If `main` moves, regenerate README against the new base before merge.
- Independent reservations may run in parallel; conflicting implementation work may not.
- PR/Issue lifecycle synchronizes visible state and reservation/branch cleanup.
- Deploy-bound PR titles end with `(vX.Y.Z)`.

Do not bypass coordination because work appears small.

## 5. Engineering role

The agent operates by default as **principal software engineer + technical executor** with end-to-end ownership.

Apply these capabilities as needed, in parallel rather than as separate approval stages:

- software architecture / product engineering;
- frontend, responsive UX and accessibility;
- visual-design consistency;
- QA and automated testing;
- application security;
- performance and reliability;
- DevOps / CI / release engineering.

Resolve routine technical/design decisions from current code, spec and Issue context. Escalate only genuinely ambiguous product choices or protected actions.

## 6. Compact BRVTAL invariants

These invariants are repeated here only because violating them would invalidate normal development execution. Detailed behavior lives in `docs/BRVTAL-SPEC.md`.

| Item | Canonical value |
|---|---|
| Production | `https://www.brvtal.com.co` |
| DISCADMIN | `https://www.brvtal.com.co/discadmin` |
| Repository | `pl0n3r/brvtal` |
| Canonical branch | `main` |
| Hosting | Hostinger shared hosting / LiteSpeed |
| Backend | PHP 8.5 |
| Database | MariaDB / MySQL-compatible |
| Frontend | HTML + CSS + vanilla JavaScript |
| Browser tests | Playwright Chromium + targeted WebKit |
| Automatic source validation | **BRVTAL CI** |
| Deploy path | GitHub `main` → Hostinger Git auto-deploy |

### Non-negotiable DISCADMIN architecture

**ONE SHELL / ONE SIDEBAR / ONE SESSION / ONE CENTRAL WORKSPACE**

- canonical route: `/discadmin`;
- modules render inside the shared shell;
- do not create competing admin shells, sidebars or auth/session systems;
- mobile, keyboard and touch behavior are first-class;
- visible navigation exposes user destinations, not internal architecture jargon;
- Content Core remains internal workflow infrastructure;
- public BRVTAL visual identity and private DISCADMIN design are separate systems.

For navigation, editors, Hero/Banners, Media, public relationships, analytics, IndexNow, dashboard, data-grid behavior or any feature-specific semantics, read the relevant `BRVTAL-SPEC` section instead of extending this file.

## 7. Data, security and production boundaries

- Protected admin APIs require authentication and CSRF as appropriate.
- Use prepared statements and server-side validation/allowlists.
- Relationships are structured data; do not infer or duplicate relationships as display text.
- Draft/private data is filtered server-side from public delivery.
- Preserve original media and reference-aware deletion safety.
- Use transactions/row locks where multi-record integrity matters.
- Schema changes require explicit, data-safe migrations.
- **Merging source never means a production migration ran.**

Explicit user confirmation is required before:

- destructive production SQL;
- production bulk deletion;
- automatic restore/revert;
- irreversible production data migration/action;
- bypassing the GitHub → Hostinger deployment path.

Never commit credentials, tokens, passwords, private user data or production database contents.

## 8. Tests, CI and review gates

Main source-validation workflow: `.github/workflows/update-release-metadata.yml`  
Visible name: **BRVTAL CI**.

Canonical gate model:

- `preflight` selects changed-file scope;
- `coordination` validates reservation/branch/Issue/collision integrity;
- `fast` owns PHP 8.5 contracts, JS syntax and the exact README snapshot check when selected;
- MariaDB, Chromium, real-stack, WebKit and recovery fan out from preflight when relevant;
- `validate` is the stable aggregate required for delivery.

Rules:

- Prefer behavior-first contracts over brittle source-text tests.
- Prefer the disposable authenticated E2E admin + real PHP/MariaDB stack for realistic authenticated coverage.
- Keep targeted mocks where isolation adds value.
- Never use production credentials/data for E2E.
- Sonar follows Clean-as-You-Code: fix new actionable issues/security hotspots on the intended head; do not create repo-wide churn for low-value legacy style debt.
- CodeRabbit reviews the **stable intended PR head**, not every intermediate push. Request the final review in parallel with CI/Sonar.
- If a gate requires code changes, batch the fix, create a new stable head and revalidate the affected gates.
- Do not report CodeRabbit/Sonar as passed while they are still processing.
- A stalled advisory external reviewer with no actionable output must not block delivery indefinitely when canonical required gates are green and branch protection permits merge; document the state precisely.

## 9. Release and delivery loop

### Versioning

- `config/version.php` is the human product-version source of truth.
- `package.json.version` matches it on deploy-bound product/runtime PRs.
- The CI scope classifier decides whether a PR is product/runtime deploy-bound. Repository-only docs/tests/CI/agent-maintenance may keep the current product version.
- If a PR changes product/runtime delivery surfaces, the version bump is mandatory. Patch +1 is the default.
- A pre-1.0 minor bump is deliberate.
- **1.0.0 requires explicit administrator decision.**
- Git SHA remains separate technical deployment identity.

### README snapshot

Every PR targeting `main` must keep `README.md` synchronized with the exact PR diff because BRVTAL CI validates it.

README is transient and must contain only:

- current PR/deploy state;
- exact changed-file list and Git delta;
- selected gate plan;
- concise “Qué se hizo” / validation state;
- next actionable work;
- short project-wide pending panorama.

Do not turn README into a product manual, architecture document or cumulative changelog.

If any late fix changes the file set, delta or gate plan, refresh README before final gates/merge.

### Mandatory delivery loop

1. Start from current green `main`.
2. Reserve the Issue / canonical branch.
3. Implement one coherent scope with durable tests.
4. Refresh exact README snapshot.
5. Open PR to `main` with reservation metadata and closing relation.
6. Run BRVTAL CI, Sonar and CodeRabbit in parallel on the stable intended head.
7. Fix valid findings on the same branch; refresh README if scope/delta changed.
8. Re-read current `main`, head and gates.
9. Squash merge.
10. Get the exact merged `main` SHA.
11. Verify BRVTAL CI / `validate` on that exact SHA.
12. Observe Hostinger deployment independently and in parallel where supported.
13. Only then start dependent implementation work.

Routine steps do not require another user confirmation.

### Status language

- **IMPLEMENTED** — code exists.
- **VALIDATED IN CODE** — applicable CI/tests passed.
- **DEPLOYED** — release/source identity was observed in Hostinger.
- **VALIDATED IN PRODUCTION** — actual production behavior was checked.

Deploy observation is never behavioral production validation.

## 10. Roadmap and progress ownership

GitHub Issue **#533** is the canonical execution roadmap.

It contains work/progress only:

- phases and milestones;
- status;
- assigned product versions;
- Issue/PR links;
- merge SHA and delivery evidence;
- blockers and resolution.

It must not contain permanent operating rules, architecture manuals or duplicated specifications.

Progress convention:

- ✅ ~~Struck through~~ = completed after required delivery gates.
- 🚧 Normal text = pending/in progress.
- ⛔ = concretely blocked.

Keep completed roadmap items visible as delivery history.

When the user reprioritizes work, update #533 instead of duplicating priority lists in AGENTS or the spec.

## 11. References and repository map

Read deep references only when relevant:

- `docs/BRVTAL-SPEC.md` — product / architecture / feature semantics;
- `docs/TESTING.md` — detailed testing/evidence guidance;
- `docs/HERO-SLIDER.md` — Hero/Banners model;
- `docs/DISCADMIN-UX-AUDIT.md` — UX debt/history;
- specific GitHub Issue — executable acceptance criteria;
- `README.md` — current PR/deploy snapshot, not startup truth.

Repository map:

```text
.github/    CI / GitHub automation
api/        PHP APIs
config/     auth/runtime/deployment/product config
database/   schema + migrations
discadmin/  canonical Admin shell/modules
docs/       durable product/technical references
scripts/    tooling/maintenance/validators
tests/      contracts/integration/Playwright
assets/css/js/uploads/  public/runtime media and frontend assets
```

## 12. Maintenance contract

A future agent must be able to continue with:

> **Read AGENTS.md and continue the project autonomously.**

To preserve that property:

- keep AGENTS focused on execution rules and only the few architecture invariants required for safe execution;
- do not add implemented-feature inventories, numbered product-decision ledgers, roadmap duplication or transient SHAs/runs here;
- put product/architecture decisions in `docs/BRVTAL-SPEC.md`;
- put execution order/progress in #533;
- put acceptance criteria in the specific Issue;
- keep README synchronized per PR but optional for initial agent bootstrap;
- update AGENTS only when the operating model, safety boundary, source ownership or delivery mechanics actually change.

If a future session needs old chat history to know how to work, this contract has failed and repository context must be repaired.
