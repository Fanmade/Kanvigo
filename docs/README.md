# Documentation

Two halves: how to *use* Kanvigo, and how to *work on or integrate with* it.
The [README](../README.md) is the front door — what Kanvigo is, the feature
list, and how to get it running.

## Using Kanvigo

- [Inviting users](using/inviting-users.md) — invitation-only onboarding, where
  the mail goes, and what a new account can do.
- [Quick notes](using/quick-notes.md) — personal notes, sharing them with a
  project, converting one into a task.
- [Reference docs](using/reference-docs.md) — a project's knowledge pages,
  drafts, nesting and backlinks.

## Developing & integrating

- [REST API](developing/api.md) — authentication, endpoints, tokens and the
  `PROJ-D3` reference scheme.
- [Audit layer](developing/audit.md) — how audited actions are emitted, and how
  to write your own sink.
- [Testing & quality](developing/testing.md) — the quality gate, the browser
  suite, and the testing conventions.

## Elsewhere

- [CHANGELOG.md](../CHANGELOG.md) — notable changes.
- `.ai/guidelines/` — the conventions agents and contributors follow in this
  repository.

Every page in this folder is listed above; `DocumentationIndexTest` fails if one
is added without an entry here.
