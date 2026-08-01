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

### Changed

- Documentation is split into usage (`docs/using/`) and developer
  (`docs/developing/`) halves, with an index at `docs/README.md`.
- The local demo seeder now creates two realistic example projects — a named
  team, typed and tagged tasks with subtasks, blockers, comments, notes and a
  doc tree — instead of placeholder text.

### Removed

- `docs/ToDo.md`, superseded by the project board.
