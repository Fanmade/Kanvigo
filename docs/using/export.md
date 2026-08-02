# Export

Take a task or a doc out of Kanvigo as Markdown — to paste into a chat, hand to
an AI assistant, or keep alongside a repository.

## What it does

Open a task or doc, choose **Export** from the actions menu (⋯), pick a
**Format** — Markdown, or a standalone HTML page — and the dialog offers two ways
out:

- **Copy to clipboard** — the document, ready to paste.
- **Download** — the same document as a file, named after the item, e.g.
  `abc-42-export-functionality.md`.

The document is the item's title as the top-level heading, followed by its
description or body: headings, lists, quotes, tables, code blocks and emphasis
all carry over.

The **HTML page** says exactly the same things as the Markdown, wrapped in a
complete, plainly styled document you can open in a browser — no stylesheets or
scripts are fetched, so it reads offline. Its metadata sits in a small table at
the top instead of in a YAML header.

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
- **Include archived** — off by default, and only offered when the subtree holds
  an archived task. Archived work aged off the board rather than being
  abandoned, and is marked as archived when included.
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
- **Inline images** follow the **Images** setting below.

## One file per item

With descendants included, **One file per item** swaps the single document for a
ZIP archive holding one Markdown file per item. **Files** then decides the shape:
all files side by side, or a folder per item that has subtasks (its own text in
`index.md` beside them). A cross-reference to an item that travels in the same
archive points at that file, so the bundle reads on its own; a reference to
anything else keeps its absolute URL.

An archive cannot go on the clipboard, so **Copy to clipboard** steps aside while
this is on. Attachments other than inline images are not bundled yet.

## Comments

When anything in the export has been commented on, **Include comments** (off by
default) adds each item's discussion after its body, under a **Comments**
heading: every comment with its author and the time it was written, oldest
first, with replies quoted under the comment they answer. A comment that was
deleted but still has replies keeps its place as *deleted*, so the thread still
reads. Comments go through the same renderer as any other content, so their
links, mentions and variables behave the same way.

## Images

When the export contains an image, an **Images** select decides how it travels:

- **Show images by URL** (default) — the file points at the image here. It
  renders for a signed-in member of the project and for nobody else.
- **List images as links** — a plain link with the file's name. Never renders
  inline, and so is honest wherever the file ends up.
- **Save images as files in the archive** — the images themselves, written into
  an `images/` folder beside the documents and linked relatively. The original
  files travel, not downscaled copies. Choosing this makes the export a ZIP even
  for a single item, since the files need somewhere to live, so **Copy to
  clipboard** steps aside.
- **Embed images in the file** — the picture itself, as a `data:` URI, so the
  file needs no access to this instance at all. Images are downscaled first, and
  once the embedded images pass a size budget the remaining ones fall back to
  links marked *image not embedded*. Copying such an export to the clipboard
  still works — you are warned about the size, not stopped.

Both limits live in `config/kanvigo.php` (`export.image_max_edge`,
`export.inline_budget`), not in a settings screen.

## Your settings are remembered

The dialog opens with the options you chose last time — per user and across every
project, because how you like your exports shaped is a habit rather than a
property of one board. Choices that no longer apply are quietly dropped: a
remembered depth clamps to a shallower subtree, "All" stays "All", and
descendants switch off for an item that has none. Nothing is exported until you
press a button, so the restored settings are always in front of you first.

**Prefix the filename with the date** (off by default) names the download
`2026-08-02_abc-42-export-functionality.md`, which sorts a folder of exports by
when they were taken. In a bundle it names the archive, and the single top
folder in the nested layout — the files inside are already under something
dated, so they stay plain. It affects file names only, not the copied text or
the content.

## Who can export

Exporting needs the **Export content** permission, held by the owner, admins and
members of a project. Viewers cannot export: reading content in place is not the
same as taking a copy of it away. Every export — copied or downloaded — is
recorded in the audit trail against the item.

## Scope

Today the export produces Markdown or an HTML page — one document, or one file
per item in a ZIP. PDF and Word, and bundling the attachment files themselves,
are planned separately; a tabular CSV listing of many tasks is a different
feature altogether.
