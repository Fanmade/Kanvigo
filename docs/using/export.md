# Export

Take a task or a doc out of Kanvigo as Markdown — to paste into a chat, hand to
an AI assistant, or keep alongside a repository.

## What it does

Open a task or doc, choose **Export** from the actions menu (⋯), and the dialog
offers two ways out:

- **Copy to clipboard** — the Markdown, ready to paste.
- **Download** — the same Markdown as a `.md` file, named after the item, e.g.
  `abc-42-export-functionality.md`.

The document is the item's title as the top-level heading, followed by its
description or body converted to Markdown: headings, lists, quotes, tables,
code blocks and emphasis all carry over.

## Metadata

**Include metadata** (on by default) adds a YAML header describing the item —
for a task its reference, title, URL, status, type, priority, tags, assignees,
due date, parent and blocking relationships; for a doc its reference, title,
URL, publication state, tags and parent. Both record when the export was taken.
Fields that are empty are left out, so a small task exports a small header.

Turn it off for a plain document with nothing but the title and the text.

## Descendants

When the item has anything nested below it, **Include descendants** (off by
default) extends the export to its whole subtree — subtasks of subtasks, subdocs
of subdocs — still as a single file. The controls that follow appear only when
they have something to do:

- **Levels** — how deep to go, counting the item's direct children as level 1.
  Defaults to **All**, which stays "all" as the subtree grows.
- **Include canceled** — off by default; canceled work reads as noise in a
  document. A skipped task takes everything below it with it.
- **Include drafts** — off by default, and only offered when the subtree holds a
  draft doc you may see. An included draft is marked as such. Exporting a draft
  directly always works; this option governs descendants only.

Each descendant becomes a heading one level deeper than its parent, in the order
the board shows it, followed by a one-line summary — reference, status, type,
assignees, tags. Markdown stops at six heading levels, so anything deeper than
that stays at the sixth.

## Links, mentions and variables

- **Cross-references** (`#ABC-42`) become links to the item's full address on
  this instance, so they still work in a file read somewhere else.
- **Mentions** become plain `@name` — a Markdown file cannot notify anyone.
- **Variables** are resolved to what they currently stand for; one with no value
  yet exports as its own name.
- **Inline images** are embedded by their absolute URL, so viewing them needs
  access to this instance.

## Who can export

Exporting needs the **Export content** permission, held by the owner, admins and
members of a project. Viewers cannot export: reading content in place is not the
same as taking a copy of it away. Every export — copied or downloaded — is
recorded in the audit trail against the item.

## Scope

Today the export produces **one Markdown file**, covering an item and
optionally its subtree. Choosing how images travel, including comments, other
formats, a multi-file bundle and a tabular CSV listing are planned as separate
steps.
