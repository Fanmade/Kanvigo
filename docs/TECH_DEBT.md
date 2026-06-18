---
# State for the /tech-debt-review playbook. Edited by the playbook each cycle.
# last_review_head: commit the previous cycle ended at. Empty = first run
#   (diff scope is skipped; only the rotation slice is reviewed).
last_review_head:
# slice_pointer: index into the rotation table in .claude/commands/tech-debt-review.md
#   0 Livewire · 1 Mcp · 2 Domain&authz · 3 Logic&helpers · 4 Edges
slice_pointer: 0
---

# Technical Debt Ledger

State and history for the monthly technical-debt review. Run it with
`/tech-debt-review`. The review **proposes** findings as Kanbrio tasks for you to
triage — it never edits, fixes, or deletes code. See the playbook at
`.claude/commands/tech-debt-review.md` for the full process.

## Wont-fix (accepted debt — never re-proposed)

Debt you have consciously decided to keep. The review reads this list first and
skips anything matching. To silence a rejected finding permanently, add a row:
copy its **fingerprint** from the Kanbrio task, give a one-line reason.

| Fingerprint | What | Why accepted | Since |
|-------------|------|--------------|-------|
| _(none yet)_ | | | |

## Metrics history

One row per cycle. The **Δ vs. the previous row is the headline**, not the absolute
value. `Health = round(0.45·MSI + 0.25·TypeCov + 0.20·LineCov + 0.10·HotspotPressure)`,
where `HotspotPressure = 100 − min(100, danger_quadrant_files × 10)`. The composite
is secondary — weighted toward mutation score because test *quality* is the honest
signal; line coverage is trend-framed and never a target.

| Date | Slice | MSI % | Type cov % | Line cov % | Danger files | Health | Δ Health |
|------|-------|-------|------------|------------|--------------|--------|----------|
| _(no cycles run yet)_ | | | | | | | |
