# Quick notes

Quick notes are lightweight, personal captures — somewhere to jot an idea that
doesn't yet belong on a board.

## What it does

Create a note from the command palette ("New note"), the **Notes** panel on the
dashboard, or the dedicated **Notes** page in the sidebar. A note has a title and an
optional rich-text body — the same editor used for descriptions, with inline images.
The Notes page lists all your notes, newest first, where you can create, edit, share,
convert and delete them; the dashboard panel shows the same notes in a compact view.
**Pin** a note (the star) to keep it at the top of the list — pinned notes sort above
the rest in both the Notes page and the dashboard panel. On the Notes page you can also
**reorder** notes with the up/down controls; ordering applies within each group, so
pinned notes always stay above the unpinned ones.

## Privacy & sharing

- A note is **private to you** by default and need not belong to any project.
- Attach a note to a project you're a member of, and you can mark it **public**. A
  public note appears, read-only, in that project's **Notes** section for every
  member; private attached notes stay yours alone.
- A note can only be public while it's attached to a project — detaching it (or never
  attaching it) forces it private again.
- Only the owner can edit, re-share or delete a note. Inline images in a public note
  are viewable by the project's members.

## Convert to task

Turn a note into a task in one step ("Convert to task"). The create-task dialog opens
prefilled with the note's title and body; choose the project (and optionally a parent
task) and save. The note is kept and shows a **Converted → PROJ-N** badge linking to
the task it produced.

## AI / MCP

The MCP server exposes note tools — create, list, get, update and convert — so an AI
agent can manage notes on your behalf. Notes are referenced by a plain numeric id,
outside the `PROJ-N` task namespace. Listing returns your own notes plus public notes
in your projects; creating, updating and converting require a write-access token.
