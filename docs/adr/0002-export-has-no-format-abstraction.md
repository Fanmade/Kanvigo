# Export ships one concrete Markdown renderer, with no format abstraction

Markdown is the first export format and PDF, HTML, Word and CSV are all planned, which
normally argues for an `ExportFormat` interface and a registry on day one. We are
deliberately not building one: export ships as a single concrete Markdown renderer plus
an `ExportOptions` value object, and the seam gets extracted when the second real format
arrives and its needs are known.

## Considered options

**An interface now** — `render(Item, ExportOptions): string`, one implementer — was
rejected because that signature is a guess we can already see is wrong. PDF produces
bytes, not a string, and cannot go on the clipboard at all; adopting the interface today
buys a wrong abstraction *and* a later migration away from it. One implementer is not
enough information to design the contract, and the cost of waiting is one refactor of
code we have to write either way.

**A document intermediate representation** that every format consumes was rejected as a
large speculative investment. Its shape can only be designed well once two real formats
disagree about what they need from the source content; designing it against Markdown
alone would encode Markdown's assumptions into the thing meant to be format-neutral.

`ExportOptions` is the one exception, extracted from the start: it is what the modal, the
persisted user preference and the audit payload all speak, so it is shared by every
format regardless of how rendering is eventually structured.

## Consequences

- **A reader will see planned formats next to a single concrete class** and may assume
  the abstraction was forgotten. It was not; this file is the record.
- **CSV is not a sibling of the other formats.** Markdown, HTML, PDF and Word render one
  item; CSV is a tabular listing of many tasks. It is a separate feature that happens to
  produce a file, and it should not be forced through whatever seam the renderers grow.
- **The HTML-to-Markdown library is chosen at implementation time**, against fixed
  criteria (per-element extensibility for cross-references, mentions, attachments and
  variable usages; maintained and PHP 8.5 compatible; configurable output style; no
  headless browser). That choice gets its own ADR, since custom converters are written
  against its API and it is the harder decision to reverse.
