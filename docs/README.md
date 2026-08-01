# Documentation

Two halves: how to *use* Kanvigo, and how to *work on or integrate with* it,
plus the decision records behind the design. The [README](../README.md) is the
front door — what Kanvigo is, the feature list, and how to get it running.

## Using Kanvigo

- [Inviting users](using/inviting-users.md) — invitation-only onboarding, where
  the mail goes, and what a new account can do.
- [Quick notes](using/quick-notes.md) — personal notes, sharing them with a
  project, converting one into a task.
- [Reference docs](using/reference-docs.md) — a project's knowledge pages,
  drafts, nesting and backlinks.
- [Export](using/export.md) — taking a task or doc out as Markdown, what the
  metadata header holds, and who may export.
- [Variables](using/variables.md) — named stand-ins for facts that recur or are
  not decided yet, written as `[name]` and resolved when the text is read.

## Developing & integrating

- [REST API](developing/api.md) — authentication, endpoints, tokens and the
  `PROJ-D3` reference scheme.
- [Audit layer](developing/audit.md) — how audited actions are emitted, and how
  to write your own sink.
- [Testing & quality](developing/testing.md) — the quality gate, the browser
  suite, and the testing conventions.

## Architecture decisions

- [0001 — Project variables substitute at read time](adr/0001-project-variables.md)
  — why `[name]` stays in the stored content and resolves on read.
- [0002 — Export ships one concrete Markdown renderer](adr/0002-export-has-no-format-abstraction.md)
  — why the format seam waits for the second real format.
- [0003 — Export converts HTML with league/html-to-markdown](adr/0003-html-to-markdown-library.md)
  — why we take a library and register our own converters for Kanvigo's nodes.

## Elsewhere

- [CHANGELOG.md](../CHANGELOG.md) — notable changes.
- `.ai/guidelines/` — the conventions agents and contributors follow in this
  repository.

Every page in this folder is listed above; `DocumentationIndexTest` fails if one
is added without an entry here.
