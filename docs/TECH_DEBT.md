---
# State for the /tech-debt-review playbook. Edited by the playbook each cycle.
# last_review_head: commit the previous cycle ended at. Empty = first run
#   (diff scope is skipped; only the rotation slice is reviewed).
last_review_head: 674d23af7691a20f1056a709e457406fec5f02ae
# slice_pointer: index into the rotation table in .claude/commands/tech-debt-review.md
#   0 Livewire · 1 Mcp · 2 Domain&authz · 3 Logic&helpers · 4 Edges
slice_pointer: 1
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
| 2026-06-18 | Livewire | 36.8¹ | 95.7 | 89.4 | 3 | 65 | — |

¹ MSI measured on the 3 danger-quadrant classes (StoryView, ProjectBoard, TaskView) via PCOV: 204 mutants, 129 untested, **36.76%** in 162s. Scoping MSI to the danger classes is deliberate — that's where test quality matters most. Headline finding: **89.4% line coverage but ~37% mutation score** there — the tests execute the code but rarely assert on its behavior, including untested `authorize()` calls (see KAN24-10). Composite `Health = round(0.45·36.76 + 0.25·95.7 + 0.20·89.4 + 0.10·70) = 65`.

### Toolchain status (all ✅)
- **Coverage driver:** PCOV installed (`php8.5-pcov`) alongside Xdebug. PCOV drives coverage/mutation automatically (Xdebug `mode` is empty); Xdebug stays for step-debugging via `XDEBUG_MODE=debug`. PCOV cut 3-class mutation from a >9-min timeout to 162s.
- **Mutation:** exclude Browser from the baseline (`--testsuite=Unit,Feature`); pass classes comma-separated in a single `--class=A,B,C` (repeated `--class` flags only honor the last); read the score from a file with the Read tool (a global hook compacts Bash stdout).
- **Type coverage:** `pestphp/pest-plugin-type-coverage` installed; **95.7%** overall.
