# Feature Documentation

Keep the documentation in sync with the application's features. Whenever you add
or modify a feature, update the docs in the same change — undocumented behavior
is treated as incomplete work.

## What to document

- The user-facing features list in `README.md`.
- Any feature-specific docs under `docs/`.

Describe **what** a feature does, not how it is implemented. Add a technical
detail only when it is relevant to using the feature (e.g. "registration is
invitation-only", "notifications auto-subscribe assignees"). Skip internal
mechanics, class names, and step-by-step implementation notes.

## Style

- Be concise and focused. Keep it basic — no trivial details.
- One feature, one short entry. Prefer a sentence over a paragraph.
- Match the tone and structure of the surrounding documentation.

## Boy Scout rule

Leave documentation cleaner than you found it. While editing any doc, fix issues
you notice in it — stale descriptions, broken references, removed features still
listed, inconsistent terminology — even if they are unrelated to your change.
