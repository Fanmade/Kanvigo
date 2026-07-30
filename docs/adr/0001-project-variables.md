# Project variables substitute at read time, over a name-keyed usage index

Projects need named stand-ins for facts that recur or are not yet decided
(`[main_protagonist]` → "Robin Hood"). We store the literal `[name]` in content
forever and resolve it to a value **when the content is read**, so changing a value
touches exactly one row and rewrites nothing. Where a name is used is tracked in a
separate `variable_usages` table keyed on the **name**, maintained asynchronously —
derived state that no render path reads.

## Considered options

**Write-time rewrite** (a value change rewrites every document that uses the
variable) was rejected: it destroys the placeholder. Once `[main_protagonist]` has
been replaced with "Robin Hood" in the text, the *next* value change has nothing to
find. Keeping both the marker and the baked value in the same string would duplicate
the source of truth and let the two drift.

The performance argument for it — reads are frequent, writes are rare — is real but
does not require it. Every rich-text read in Kanvigo already does two full HTML
passes (`MentionLinker`, then `RichTextSanitizer`); substitution is a cheaper third
one, and if it ever measures badly the answer is caching the *rendered* output,
which is the same domain model plus an invalidation path the usage index already
supports. Read-time-now, cache-later needs no re-modelling and no content migration.

**Id-keyed usages** (an atomic editor node carrying a variable id, like Kanvigo's
`#reference` links) was rejected because content is authored heavily through the MCP
server, and an agent cannot practically emit `<a data-type="variable" data-id="7">`.
A syntax an agent cannot write is half a feature for a tool whose main use case is
AI-assisted drafting. Name-keying also makes a usage of a not-yet-created or
deleted variable representable — which is a feature (surface unknown names, make
deletion reversible), not a gap.

## Consequences

- **Renaming is the one operation that rewrites content**, behind a confirmation.
  This looks inconsistent with "a value change never rewrites anything" and is not:
  a rename changes what the pointer is *called* while the document says the same
  thing, so rewriting is what keeps it saying it. It moves `updated_at` and shows as
  a real edit, because the stored bytes really did change.
- **The usage index may lag**, and that is safe by construction: rendering resolves
  names against the `variables` table directly, so a failed or delayed job degrades
  a panel, never a page. A `variables:reindex` command exists because derived state
  maintained by a queue needs a rebuild path.
- **The index has no foreign key** to `variables`. Integrity comes from rebuilding
  from content, not from a constraint; a FK would be false comfort and would break
  the deliberate ability to record unknown names.
- **Variables do not work in notes.** A note is user-owned and projectless, so it
  has no variable namespace; letting one participate would mean attaching a note to
  a project silently changes what its existing text says. If notes are ever
  included, substitution follows the note into the project it is attached to, and
  that silent change is accepted.
- **Variables do not work in titles.** A title is an identifier — it appears in
  notifications, search results, audit entries and MCP output — and must mean the
  same thing everywhere and over time. Prose has no such obligation.
- **A value change produces one audit event on the variable**, not one per affected
  document. The documents were not edited, and claiming they were would corrupt both
  their history and every recently-updated ordering in the project.
