# Export converts HTML to Markdown with league/html-to-markdown

Rich text is stored as HTML, so every Markdown export starts by converting it. We take
`league/html-to-markdown` (MIT, requires only `ext-dom` and `ext-xml`, no transitive
dependencies) and register our own converters on its environment for the nodes only
Kanvigo knows about: cross-references, mentions, attachment images and strikethrough.
Everything else — escaping, lists, headings, tables, blockquotes, fenced code — is the
library's job.

The deciding criterion was **per-element extensibility**, and it was verified before the
choice, not assumed: a spike converted a real task description containing a `#KAN-D1`
reference, both mention shapes, an attachment thumbnail linking to its full-size file, and
a paragraph of literal `*`, `_`, `#`, `|` and backticks. Registering a `ConverterInterface`
for a tag replaces the built-in one for *every* element with that tag, so our anchor
converter handles `data-type="reference"` and `data-type="mention"` and delegates plain
links back to the library's `LinkConverter`. That delegation is the whole reason the
approach holds: our nodes are special, ordinary content is not.

## Considered options

**Hand-rolling the converter** on `Dom\HTMLDocument` — which the app already uses in
`VariableSubstitutor` — was rejected. The custom nodes are the easy part; the cost sits in
the boring part we would inherit forever: escaping literal Markdown punctuation in user
prose, list nesting and continuation indentation, tables, code fences that contain
backticks. That is a well-solved problem with a well-tested implementation, and writing it
ourselves buys control over the 10% we care about at the price of owning the 90% we do not.

**`pixel418/markdownify`** was rejected: it declares `php >=5.3`, has seen no meaningful
release in years, and offers no per-element extension seam.

**`kntnt/html-to-markdown`** (a PHP 8.4 port of the Go GFM converter) is the more modern
codebase and a genuine future option, but it is at v0.1.x with a single maintainer. For a
dependency that our custom converters are written against, maturity beats freshness.

**`xberg-io/html-to-markdown`** was rejected outright: it is a thin API over a native Rust
PHP extension. Kanvigo is self-hosted, so requiring a compiled extension to export a task
is a deployment tax paid by every operator.

## Consequences

- **The library is mature, not busy.** Its last functional commit is from 2024 and its last
  release from 2023; the repository is maintained (CI upkeep, issues open) but effectively
  finished. We accept that: the surface we depend on is small, our converters are ours, and
  a stalled dependency here fails visibly at conversion time rather than silently.
- **Construct it as `new HtmlConverter([...options])`, then add converters via
  `getEnvironment()`.** Building from a bare `Environment` skips the defaults array, and the
  built-in converters read their style options from config — an unset `italic_style` makes
  `<em>` silently render as plain text. This is a real trap; it cost the spike a round.
- **`TableConverter` is opt-in** and must be registered explicitly, and `suppress_errors`
  must stay on: the library parses with `DOMDocument`, an HTML4 parser, which emits warnings
  for HTML5-only tags such as `<mark>`.
- **`<u>`, `<mark>`, `<sub>` and `<sup>` pass through as inline HTML.** Markdown has no
  syntax for them, and inline HTML is valid Markdown; we do not strip content to satisfy
  purity.
- **Variable usages need no converter.** They are plain `[name]` text resolved by
  `VariableSubstitutor` before conversion, so they arrive as ordinary text — and the
  library's escaping of an unresolved `[name]` is correct behaviour, not a bug.
- **If a second format ever needs a different traversal**, this choice is contained: the
  library is an implementation detail of the Markdown renderer, which
  `docs/adr/0002-export-has-no-format-abstraction.md` already keeps concrete.
