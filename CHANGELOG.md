# Changelog

Notable changes to Kanvigo, in the format of
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

This file starts here rather than reconstructing the past: Kanvigo is pre-1.0
and everything before this point is in the git history and on the project board.

Kanvigo is under active development and does not yet follow semantic versioning.

## [Unreleased]

### Added

- **Project variables** — named stand-ins for facts that recur or are not decided
  yet. Write `[main_protagonist]` in a description, doc body or comment and it
  shows the project's current value for it; an unset variable renders as a visible
  hole. Values resolve when the text is read, so changing one changes every place
  at once and rewrites nothing. Includes a per-project management page with usage
  lists and history, a `[` picker in the editor with create-on-demand, renaming
  that rewrites usages, command-palette search by name or value, MCP tools, and a
  REST endpoint. See [docs/using/variables.md](docs/using/variables.md).

### Fixed

- The REST task detail response (`GET /api/v1/tasks/{reference}`) now returns the
  task `description`. The API accepted one on create and update but never gave it
  back, so a client could not read what it had written. The task *list* stays lean
  and still omits it.

### Changed

- Documentation is split into usage (`docs/using/`) and developer
  (`docs/developing/`) halves, with an index at `docs/README.md`.
- The local demo seeder now creates two realistic example projects — a named
  team, typed and tagged tasks with subtasks, blockers, comments, notes and a
  doc tree — instead of placeholder text.

### Removed

- `docs/ToDo.md`, superseded by the project board.
