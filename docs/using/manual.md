# User manual

A guided tour of Kanvigo for the person using it day to day. It follows the path
you actually take — get in, find your way, run a project, write things down,
keep up — and links to the deeper pages where one exists.

## Getting in

Kanvigo is **invitation-only**: there is no sign-up page. Someone already on the
instance invites you, and you get a signed email link that expires. Follow it,
pick a password, and the account is yours. See
[Inviting users](inviting-users.md) for the other side of that flow.

After the first sign-in you may be asked to **verify your email** — a second
link, sent to the same address. Until you do, most pages stay out of reach.

You can make signing in stronger from [Security settings](#your-account):
**two-factor** with an authenticator app, or a **passkey** for signing in without
a password at all.

## Finding your way

The sidebar carries the pages that are not tied to one project:

| Page | What it is for |
| --- | --- |
| **Dashboard** | Your own status: counts per status, a "My tasks" list, your recent completions and your notes |
| **Projects** | Every project you can see; where new ones are created |
| **Board** | One Kanban board across all your projects |
| **Notes** | Your personal notes |
| **Activity** | Everything that happened across your projects |
| **Notifications** | What was addressed to you |

Everything else hangs off a project.

### URLs are readable

Addresses are made of the project's short name, so they can be typed, pasted
into a chat and recognised in a browser history:

- `/ABC` — the project
- `/ABC/board` — its board
- `/ABC-42` — task 42 in it
- `/ABC-D3` — reference doc 3 in it

The browser tab title carries the same context, so a dozen open tabs stay
distinguishable.

### The command palette

`⌘K` (or `Ctrl+K`) opens the palette — the fastest way anywhere, and the one
keyboard shortcut worth remembering. It searches **projects, tasks, docs and
variables** across everything you can see, and it understands more than plain
text:

- type a reference — `ABC-42`, the compact `ABC42`, or `ABC-D3` — and the exact
  item is pinned to the top;
- type a bare number like `42` and you get that task in every project, the one
  you are in first;
- type a tag name and the tagged tasks come back;
- type part of a variable's name *or its value* and you get the pages that use
  it.

Below the results sit **quick actions**: new task, new note, new doc, new
project, invite a user, export the item you are looking at. The list narrows as
you type, and actions you have no permission for are simply absent.

## Projects

A project groups work. It has a title, a description, and a **short name** of
two to four uppercase letters that every reference is built from.

Create one from **Projects → New project** (if your account may create
projects). Changing the short name later updates the links to it.

The project page is the hub:

- the description, written in the same rich editor as everything else;
- **Tasks** — the top-level tasks as cards, each with its subtasks as quick
  links, an open-task count and a progress bar over the whole subtree. Closed and
  archived work is tucked into its own groups until you ask for it. Filter by
  priority, tags and assignees, matching *any* or *all* of what you picked;
- **Notes** — notes other members shared into the project, read-only unless they
  are yours;
- comments, for discussion about the project itself;
- an actions menu holding everything else: Docs, Board, Export, and the settings
  pages below.

### Project settings

Reachable from the project's actions menu — they are deliberately not in the
sidebar, and each needs the matching permission:

- **Tags** — colour-coded labels with optional icons and *synonyms* they are also
  found by. Rename, recolour, merge or delete them here.
- **Task types** — the project's own catalog (Feature, Bug and Chore to start
  with). Reorder them, give them colours and icons; deleting one leaves its tasks
  untyped.
- **Variables** — see [Variables](variables.md).
- **Roles** — who may do what in this project; see [Roles and access](#roles-and-access).
- **Members** — add and remove people, and give each of them one or more roles.
- **Auto-archive** — how many days a task stays in Done before it is archived off
  the board. Blank uses the instance default, `0` switches it off.

## The board

The board has four columns — **Planned**, **To do**, **In progress** and
**Done** — and comes in two flavours: one **per project** (`/ABC/board`) and one
**global** (`/board`) spanning every project you can see.

Drag a card to another column to change its status, or drag it within a column
to set the order by hand. If dragging is awkward — on a phone, or with a
keyboard — every card's **⋯** menu has "Move to …" for the same thing, plus
archive and unarchive.

Each column has its own **search** behind the magnifier, matching title or
reference in that lane only.

The project board adds a **Filters** popover — priority, task type, assignee
(with "Assigned to me" first) and whether archived cards are shown. The global
board keeps only "Show archived" and the per-column search.

Cards show what you need to triage at a glance: the breadcrumb of parent tasks,
tags, type, priority, assignees, an overdue due date, and a **Blocked** marker
while something it depends on is still open.

**Live updates** keep the board current — it refreshes itself every few seconds,
and never in the middle of a drag. The toggle in the header turns it off, and
Kanvigo remembers your choice.

Moving a subtask sometimes prompts you about its parent — "mark the parent in
progress?", "close the parent too?" — which is the board keeping the tree honest
rather than changing things behind your back.

## Tasks

A task is the unit of work, addressed as `ABC-42`. It carries a title, a
rich-text description, and in the side rail: **status**, **priority** (five
levels; a subtask inherits its parent's), **type**, **assignees** (several
people can share one task — "Assign to me" is one click), **due date**,
**tags**, its **parent**, its **relationships** and its **links**.

### Moving work along

Set the status from the rail, or step it with the ◀ ▶ buttons next to it. When
you finish a task that still has open subtasks, Kanvigo asks what to do with
them — only this one, mark them all done, or cancel them — and can remember your
answer for next time.

### Subtasks

Any task can hold subtasks, and those can hold their own, down to a configured
depth. A task with subtasks shows a progress bar over its **whole** subtree, not
just its direct children. "Move task" re-parents a task or lifts it to the top
level.

### Ending a task

Two ways, both reversible, both keeping the history:

- **Cancel** — abandoning it, with a reason (Won't fix, Duplicate, Deprecated)
  and an optional note. Its open subtasks are cancelled with it; the count is
  shown before you confirm. A cancelled task wears a banner with a **Reopen**
  button.
- **Archive** — filing finished work away so it stops filling the board. Done
  tasks are archived automatically after the project's threshold. Archiving is
  done from the board card menu or the project page.

Deleting is not the normal path — cancelling says *why* something stopped, which
a deletion never can.

### Relationships

Tasks link to each other with a type: **blocks / blocked by**, **relates to**,
**duplicates**, **clones**, **causes**. Only blocking affects scheduling — a card
is flagged *Blocked* while any blocker is still open. Cycles are refused.

### Attachments and comments

Drop files onto the description to attach them, several at once. Images and PDFs
get thumbnails, and images open in a lightbox — **Esc** closes it, **←** and
**→** move between them. SVG files are the exception: they can be attached and
downloaded, but they are never previewed or displayed in the page, because an SVG
can carry code.

Comments sit under the task, support one level of replies, and can be edited or
withdrawn (leaving a tombstone rather than a silent gap). They arrive live
without disturbing a reply you are in the middle of typing, and you can collapse
a person's comments if a thread gets long.

## Writing

Descriptions, doc bodies, notes and comments all use the same editor: headings,
lists, links, quotes, code, tables, and images pasted or dropped straight in.

Three characters do more than they look:

| Type | You get |
| --- | --- |
| `@` | A **mention** of a project member — it notifies them and subscribes them to the item |
| `#` | A **reference** to a task or doc, stored as a live link with a hover preview |
| `[` | A **variable** — a named stand-in for a fact, see [Variables](variables.md) |

A `#` reference written in a task description or a doc body also **links the two
items**: the target grows a "Referenced by" entry that disappears when you
remove the text. In a comment it stays an ordinary link — a discussion is not
the item's own text.

These three work wherever there is a project to resolve them against, which
means everywhere except a personal note.

## Notes and docs

Two different things, easily confused:

**[Quick notes](quick-notes.md)** are yours. Private by default, they need no
project, and they are the place for a thought that has not earned a task yet.
Pin them, reorder them, search them — and when one turns out to be real work,
**convert it to a task** in one step.

**[Reference docs](reference-docs.md)** belong to a project. Numbered `ABC-D3`,
they have no status and are never "done": specs, decisions, conventions,
background. They nest into a tree, start as **drafts** visible only to those who
may edit docs, and list both what they cite and everything that cites them.

Rule of thumb: if it will be *done* one day, it is a task; if it will be *read*
for months, it is a doc; if you are not sure yet, it is a note.

## Keeping up

Two pages that sound alike and are not:

- **Notifications** is what was addressed to *you* — something happened on an
  item you follow, or someone mentioned you. The bell in the header carries the
  unread count; the page has an **Inbox** (filter by read state, project,
  activity type and period, with bulk mark-read and dismiss) and a
  **Subscriptions** tab listing everything you follow.
- **Activity** is everything that happened across your projects, newest first and
  grouped by day. It is not addressed at you — it answers "what did I miss?".
  Filter by person, project, type and period; your own activity is left out
  unless you ask for it. A line marks what arrived since your last visit, and a
  filtered feed keeps its state in the URL, so you can share it.

You are **subscribed automatically** when you get involved: creating a task,
being assigned, commenting, being mentioned. The bell on a task toggles it by
hand. Unsubscribing sticks — no later trigger quietly puts you back; only you
can.

Notifications are shown inside Kanvigo. There are no email alerts.

## Roles and access

What you may do in a project comes from the **roles** you hold there, and you
may hold several — your permissions are the union of them.

Every project starts with four: **owner**, **admin**, **member** and a read-only
**viewer**. Beyond those, a project can define its own roles, each created under
a parent role and bounded by it — a role can never hand out more than the role
it came from, so delegation cannot escalate.

The project's **Roles** page shows the tree, what each role may do, and who
holds it. You only ever see roles at or below your own, and you cannot edit a
role you hold yourself.

Account-level permissions — creating projects, inviting users, seeing every
project — are managed by administrators in user administration, either
individually or bundled into named **account roles**.

## Getting things out

Any task or doc can be **exported** from its ⋯ menu, as Markdown or a standalone
HTML page, copied to the clipboard or downloaded. Options cover the metadata
header, the whole subtree, comments, cancelled and archived items, and how
images and attachments are handled — or one file per item as a ZIP.
Administrators can export a whole project at once. Your choices are remembered
for next time. See [Export](export.md).

For programmatic access there is a REST API and a set of MCP tools for AI
assistants; personal tokens are created in
[API tokens settings](#your-account). The details live in the
[REST API](../developing/api.md) page.

## Your account

Under **Settings**:

- **Profile** — your name, email, and an avatar (initials stand in without one).
- **Appearance** — light, dark or follow the system; English or German; and a
  full-width layout for large displays. Theme and language are also in the
  account menu.
- **Security** — change your password, set up **two-factor** (authenticator app
  plus recovery codes) and manage **passkeys** for passwordless sign-in.
- **API tokens** — personal tokens with an access level (read-only, read and
  write, or the audit event stream) and an optional restriction to chosen
  projects. A token is shown once, at creation. The same page lists **connected
  applications** — AI assistants and other clients you authorized — and revokes
  them.

Security and API tokens ask for your password again before opening, even though
you are signed in.
