---
description: Monthly technical-debt review — deterministic metrics + LLM smell/security review, proposed into Kanbrio as triage tasks. Propose-only; never auto-fixes or deletes.
argument-hint: "[--slice=<name>] (optional: force a specific rotation slice)"
---

# Tech-Debt Review Playbook

A recurring review that hunts code smells and improvement opportunities across
**stability, maintainability, and security**, and tracks honest health metrics
over time. It **proposes** — you triage. It never edits, fixes, or deletes code.

State lives in `docs/TECH_DEBT.md` (the ledger): `last_review_head`,
`slice_pointer`, the wont-fix suppression list, and the metrics history table.

> Coverage/mutation need Xdebug with `XDEBUG_MODE=coverage`. These runs are slow
> and must **not** use `--parallel`. Run them separately from `composer test`.

---

## Step 0 — Read the ledger first

Read `docs/TECH_DEBT.md`. Extract from the frontmatter:

- `last_review_head` — the commit the previous cycle ended at (scope floor).
- `slice_pointer` — index into the rotation list below; this cycle reviews that slice.
- The **wont-fix** list (accepted debt — never re-propose) and its fingerprints.

If `$ARGUMENTS` contains `--slice=<name>`, use that slice instead of the pointer
(don't advance the pointer in that case).

**Rotation slices** (one per cycle; full legacy coverage every 5 cycles):

| # | Slice | Paths |
|---|-------|-------|
| 0 | Livewire | `app/Livewire` |
| 1 | Mcp | `app/Mcp` |
| 2 | Domain & authz | `app/Models`, `app/Policies` |
| 3 | Logic & helpers | `app/Actions`, `app/Concerns`, `app/Support` |
| 4 | Edges | `app/Http`, `app/Notifications`, `app/Mail`, `app/Console`, `app/Providers`, `app/Contracts`, `app/Enums` |

---

## Step 1 — Deterministic prechecks (cheap, no LLM)

Run and record results. These are the first line — never spend LLM effort on what
these already flag.

```bash
composer audit                 # dependency CVEs (PHP)
npm audit                      # dependency CVEs (JS)
vendor/bin/pint --test --format agent   # style baseline (report only)
composer types:check           # larastan baseline — must NOT grow vs last cycle
php artisan test --testsuite=Unit,Feature --parallel   # suite must be green
```

Note any CVE, any new larastan error vs. the last cycle's count, any failing test.
A growing larastan baseline is itself a finding.

---

## Step 2 — Collect metrics

Run separately, **non-parallel**. **PCOV** (`php8.5-pcov`) is installed and is the
active coverage driver (Xdebug's `mode` is empty), so coverage/mutation run at near-
native speed with **no `XDEBUG_MODE`**. Pass `-d pcov.directory=app` to limit
instrumentation to source. (Xdebug remains for step-debugging via `XDEBUG_MODE=debug`.)
If PCOV is ever absent, prefix the coverage/mutation commands with `XDEBUG_MODE=coverage`
as a slow fallback.

> **Reading metric output (important).** A global hook in this environment compacts
> tool stdout into `{"tool":"pest",…,"raw":[…]}` when read via Bash, so the human
> tables/scores are hidden from a normal Bash result. **Always redirect each metric
> command to a file and open it with the Read tool** (not `cat`/`grep`/`tail` — those
> are Bash and get compacted too). The real numbers are in the `raw` array: type
> coverage ends with `Total: NN.N %`; mutation ends with `Mutations: a untested, b
> tested` and `Score: NN.NN%`.

```bash
# Line coverage — emit machine-readable clover, exclude the Browser suite
php -d pcov.directory=app artisan test --testsuite=Unit,Feature \
  --coverage-clover=/tmp/td-clover.xml > /tmp/td-line.txt 2>&1

# Type coverage (plugin: pestphp/pest-plugin-type-coverage). Read /tmp/td-typecov.txt → "Total: NN.N %"
vendor/bin/pest --type-coverage > /tmp/td-typecov.txt 2>&1

# Mutation score — MUST pass --testsuite=Unit,Feature (Browser tests time out and break the
# baseline). Pass classes COMMA-SEPARATED in one --class (repeated --class flags only honor the
# last). Scope to the diff + danger-quadrant classes, NOT the whole slice. Read → "Score: NN.NN%".
php -d pcov.directory=app vendor/bin/pest --mutate --covered-only --testsuite=Unit,Feature \
  --class="App\\Foo\\Bar,App\\Foo\\Baz" > /tmp/td-mutate.txt 2>&1

# Architecture fitness functions (pass/fail) — only if arch tests exist
php artisan test --filter=Arch    # skip if none defined yet
```

Then parse overall + per-file line coverage from the clover XML (statements covered /
total), e.g. with a short `php -r` over `/tmp/td-clover.xml`'s `<metrics>` nodes.

> **Mutation scoping & speed.** With PCOV, the 3 danger-quadrant classes mutate in
> ~160s (204 mutants); whole Livewire slices are still many minutes, so keep `--class`
> scoped to the **diff** plus the **danger-quadrant** classes (from Hotspots below).
> If it still can't finish in budget, record MSI as a sampled value (note which
> classes) rather than blocking the cycle. Note: line coverage and mutation score
> diverge sharply — high line coverage with low MSI means tests execute but don't
> assert; that gap is itself a finding worth filing.

**Diff-coverage**: from the per-file coverage table, read the coverage of files
changed since `last_review_head` (`git diff --name-only <last_review_head>...HEAD`).
Flag any changed file whose coverage dropped or sits notably below the repo trend.

**Hotspots** (churn × complexity — no tooling):

```bash
# Churn: change frequency since last review (or last ~90 days if first run)
git log --since="<last_review or 90 days>" --name-only --pretty=format: -- 'app/*.php' \
  | grep -E '\.php$' | sort | uniq -c | sort -rn | head -20
```

Cross the top-churn files with their size/complexity (lines, nesting depth, method
count). Files that are **both** high-churn and complex are the danger quadrant —
that's where bugs concentrate. List them; the worst become review focus and may
become findings.

---

## Step 3 — Load suppression

1. From the ledger's **wont-fix** list, collect accepted-debt fingerprints.
2. Via the Kanbrio MCP, list existing debt items so you don't duplicate them:
   - `list-stories` on the project; find prior "Tech-debt review …" stories.
   - `list-tasks` on those stories; collect open and closed-as-wontfix task titles/fingerprints.

Anything matching either source is **skipped** in Step 5.

---

## Step 4 — Determine review scope

- **Diff scope**: all files changed since `last_review_head`
  (`git diff --name-only <last_review_head>...HEAD`).
- **Slice scope**: every PHP file under the current slice's paths (Step 0).

Review the union of both.

---

## Step 5 — Review across three dimensions

For each file in scope, review for the dimensions below. For a thorough pass you
**may** fan out one agent per dimension via a Workflow — but the dimensions and
their checks are fixed here so the process is deterministic.

**Stability**
- Driver-specific SQL / behavior that passes on local SQLite but breaks on prod
  PostgreSQL (the recurring trap — be strict here).
- Unhandled error paths, swallowed exceptions, missing transaction boundaries.
- N+1 queries, missing indexes implied by query patterns.
- Race conditions, non-idempotent jobs/listeners.

**Maintainability**
- Code smells: long methods, deep nesting, duplicated blocks, primitive obsession,
  feature envy, god classes.
- **Dead code**: unused methods/classes/routes/config, unreachable branches,
  commented-out code. (Propose removal — never delete here.)
- Convention drift from sibling files; static-closure rule violations.
- Files flagged as hotspots in Step 2 get extra scrutiny.

**Security**
- Authorization gaps: missing policy checks, `authorize()` omissions, IDOR.
- Mass-assignment exposure, unvalidated/untrusted input, raw SQL/`DB::raw`.
- Leaked secrets, unsafe file/`imagick`/`gd` handling, SSRF in fetches.
- Anything surfaced by `composer audit` / `npm audit` in Step 1.

---

## Step 6 — Fingerprint & dedup

For every candidate finding, compute a stable fingerprint:

```
fingerprint = sha1( relative_file_path + "::" + symbol + "::" + normalized_description )
```

`symbol` = the class/method/function it lives in. `normalized_description` =
lowercase, whitespace-collapsed one-line summary. **Never use line numbers** —
they shift on refactor and would defeat suppression.

Drop any finding whose fingerprint matches the wont-fix list or an existing
open/wontfix Kanbrio item from Step 3.

---

## Step 7 — File survivors into Kanbrio (propose only)

Via the Kanbrio MCP:

1. Create one **story**: `Tech-debt review YYYY-MM` (use the current month).
2. Create one **task per surviving finding** under that story. Each task:
   - Title: short, specific, includes the dimension tag, e.g.
     `[security] StoryPolicy missing update authz check`.
   - Body: the finding, the file/symbol, why it matters, a proposed fix, and the
     fingerprint (for future dedup).
   - **Priority** by severity (your `Priority` enum):

     | Severity | Priority | Examples |
     |----------|----------|----------|
     | Security vuln / data-loss / prod crash | `Highest` | IDOR, mass-assignment, driver SQL that 500s in prod |
     | Significant correctness / security risk | `High` | swallowed exception on a write path, N+1 on hot route |
     | Real maintainability cost | `Medium` | god class, duplicated logic, dead public method |
     | Minor smell / cosmetic | `Low` | naming, small dead branch, convention drift |
     | Trivial / nice-to-have | `Lowest` | doc nits, micro-cleanups |

3. Also file metric-driven findings as tasks where warranted, e.g. "diff-coverage
   dropped on `X`", "new danger-quadrant hotspot `Y`", "mutation survivors in `Z`".

Dead-code removal is always a **proposed task**, never executed in this run.

---

## Step 8 — Scorecard & ledger update

**Compute the scorecard.** Components normalized to 0–100:

- Mutation score (MSI), %
- Type coverage, %
- Line coverage, %
- Hotspot pressure = `100 − min(100, danger_quadrant_files × 10)`

Transparent composite (the **Δ vs last cycle is the headline**, not the absolute):

```
Health = round( 0.45·MSI + 0.25·TypeCov + 0.20·LineCov + 0.10·HotspotPressure )
```

The composite is **secondary** — weighted toward mutation score because test
*quality* is the honest signal; line coverage is low-weighted and trend-framed so
it can't be gamed into a target.

**Update `docs/TECH_DEBT.md`:**

1. Append a row to the **Metrics history** table: date, slice reviewed, MSI,
   type cov, line cov, danger-quadrant count, Health, and Δ vs the previous row.
2. Set `last_review_head` to the current `HEAD` (`git rev-parse HEAD`).
3. Advance `slice_pointer` to `(slice_pointer + 1) mod 5` — unless `--slice` was forced.
4. Leave the **wont-fix** list untouched. (You move rejected findings there by hand,
   one line + reason + fingerprint, to silence them permanently.)

**Report** to the user: the scorecard with deltas, count of findings filed by
dimension and priority, anything notable from the prechecks (CVEs, larastan drift,
failing tests), and which slice was reviewed.

---

## Guardrails

- **Propose only.** No edits, no fixes, no deletions in this run — ever.
- **No vanity metrics.** Coverage % is never a gate or target; only its trend and
  diff-coverage matter. Don't add LOC/commit-count/grade metrics.
- **Suppression is sacred.** If a fingerprint is in wont-fix or already filed, it
  does not come back.
- **Deterministic first.** Lean on `audit`/`larastan`/`pint`/tests before LLM review.
