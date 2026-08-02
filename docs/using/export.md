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
- **Pick items…** — swap the level for the tree itself and tick exactly what you
  want. Ticking an item ticks everything below it; unticking one leaves its own
  subtree alone, and anything still ticked below a gap moves up a level so the
  document never skips a heading. The tree starts from whatever the level
  covered, and *Choose by level instead* goes back. A hand-picked set applies to
  that one export — reopening the dialog starts again from the level.
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

Each file also carries its own way around the tree: a link **Up** to the item it
sits under, and **Below** to the items nested directly beneath it — both
relative, and only ever to files the archive actually contains.

An archive cannot go on the clipboard, so **Copy to clipboard** steps aside while
this is on.

An archive is not always a bundle: choosing to carry images or attachments as
files produces one too, holding a single document and the files beside it.

## Attachments

When something in the export has a file attached to it, an **Attachments**
select decides whether those files travel. **Include the files in the archive**
writes each one into an `attachments/` folder and lists it under the item it
belongs to, with its size — an attached file is nowhere in the text, so without
that listing the archive would hold files nobody finds. Like the image files
mode, it makes the export a ZIP and steps **Copy to clipboard** aside.

Inline images are not part of this: they are already in the text and follow the
**Images** setting instead.

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

## The quick way

On a task or doc page, the command palette (⌘K / Ctrl+K) offers **Export this
item**: it exports straight away with the settings you last used, without opening
the dialog at all. The result lands on the clipboard — and when your remembered
settings need an archive (a bundle, or images or attachments as files) it
downloads instead and tells you so, since an archive cannot be pasted.

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

## Exporting a whole project

A project's action menu (⋯) offers **Export project**: every top-level task with
its subtree and every doc you may see, each as its own file, in one archive.
Tasks and docs sit in their own folders, mirroring the two reference namespaces
on the board, and cross-references between them resolve inside the archive.

The same choices apply — format, layout, metadata, comments, canceled, archived,
drafts, images, attachments, date prefix — minus the ones that make no sense at
this scale: it is always every level, always one file per item, and never a copy
to the clipboard. The dialog shows how many items the archive will hold before
you commit to it.

The archive is built while you wait, so it is bounded: past
`kanvigo.export.max_project_items` (2000 by default, in `config/kanvigo.php`) the
export is refused with the actual count rather than timing out.

## From the API

Machines fetch the same documents over the REST API:
`GET /api/v1/tasks/{reference}/export` and `/api/v1/docs/{reference}/export`, with
the options as query parameters. See
[docs/developing/api.md](../developing/api.md#exporting-an-item).

## Who can export

Exporting an item needs the **Export content** permission, held by the owner,
admins and members of a project. Viewers cannot export: reading content in place
is not the same as taking a copy of it away.

Exporting a **whole project** needs the separate **Export the whole project**
permission, which stops at admins and the owner — taking a board out in one
archive is a different act from exporting the task you are reading.

Every export is recorded in the audit trail: against the item for a content
export, against the project for a project one.

## Scope

Today the export produces Markdown or an HTML page — one document, or one file
per item in a ZIP. PDF and Word, and bundling the attachment files themselves,
are planned separately; a tabular CSV listing of many tasks is a different
feature altogether.
