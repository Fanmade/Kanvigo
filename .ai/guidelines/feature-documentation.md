# Feature Documentation

Keep the documentation in sync with the application's features. Whenever you add
or modify a feature, update the docs in the same change — undocumented behavior
is treated as incomplete work.

## What to document

- The user-facing features list in `README.md`.
- The `## [Unreleased]` section of `CHANGELOG.md`, for anything a user would
  notice (added, changed, fixed, removed).
- Any feature-specific docs under `docs/`.

Describe **what** a feature does, not how it is implemented. Add a technical
detail only when it is relevant to using the feature (e.g. "registration is
invitation-only", "notifications auto-subscribe assignees"). Skip internal
mechanics, class names, and step-by-step implementation notes.

## Where a page belongs

`docs/` has two halves, and a new page goes in exactly one of them:

- `docs/using/` — how to *use* a feature, written for someone working in the
  app. No class names, no request/response shapes.
- `docs/developing/` — how to *work on or integrate with* Kanvigo: the REST API,
  the audit layer and its sinks, the quality gate.

Add the page to the index in `docs/README.md` in the same change —
`DocumentationIndexTest` fails otherwise. Repository conventions for agents and
contributors are not documentation: those live in `.ai/guidelines/`, and
machine-maintained playbook state lives in `.ai/state/`.

## Style

- Be concise and focused. Keep it basic — no trivial details.
- One feature, one short entry. Prefer a sentence over a paragraph.
- Match the tone and structure of the surrounding documentation.

## Boy Scout rule

Leave documentation cleaner than you found it. While editing any doc, fix issues
you notice in it — stale descriptions, broken references, removed features still
listed, inconsistent terminology — even if they are unrelated to your change.
