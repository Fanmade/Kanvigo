# Bug Reports

When the user reports a bug — unexpected or broken behavior, a failing page, or a
pasted error/stack trace they want fixed — open a tracking task on the Kanvigo
board **before** you start diagnosing or fixing. Recording the defect first keeps
the board the source of truth for what broke and what was done about it.

## Create the task first

- Create a task on this project's Kanvigo board (`KAN`) with the Kanvigo MCP
  create-task tool, tagged `bug`, before any investigation.
- **Title**: a short, specific symptom — e.g. "Project board 500s with
  `__PHP_Incomplete_Class` on load", not "board broken".
- **Description**: what the user observed, the error/stack trace, and where it
  happens. Capture the report, not the fix.
- If a task for the same defect already exists, reuse it rather than duplicating.
  Nest under an obvious umbrella task when one exists (e.g. a "Small bugs"
  parent); otherwise create it top-level.

## Then work it

- Move the task to **In progress** when you start investigating, and to **Done**
  once the fix is verified (tests green).
- Genuine defects only. Skip this for feature requests, questions, refactors, or
  planning — those follow the normal flow.
