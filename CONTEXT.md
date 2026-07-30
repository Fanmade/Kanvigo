# Kanvigo

Kanvigo is a project-management board: projects hold nestable tasks and reference
docs, and the people working on them collaborate through descriptions, comments and
activity. This file is the glossary — the words we use for the concepts, and the
words we deliberately don't. It is not a spec: it says what things *are*, never how
they are built.

## Projects and people

**Project**:
The container for a body of work. Holds tasks, docs, tags and task types, and scopes
who may see them. Identified by a short name of 2–4 uppercase letters (`KAN`).

**Member**:
A user who belongs to a project. What they may do there comes from the roles granted
to them in that project, never from a fixed rank.
_Avoid_: Collaborator, participant

## Work items

**Task**:
A unit of work in a project. Tasks nest without limit: a task's children are
themselves tasks, and a top-level task is simply one without a parent.
_Avoid_: Ticket, issue, card, story

> There is no separate "story" or "epic" entity — that distinction was deliberately
> removed. An epic is just a task with children.

**Subtask**:
A task's direct child. A relative term, not a kind of thing: the same task is a
subtask of its parent and a parent to its own children.

**Status**:
Where a task stands in the working flow: Planned, ToDo, In progress, Done.
Cancellation is not one of these.

**Cancellation**:
Abandoning a task with a reason (WontFix, Duplicate, Deprecated) and an optional
message, which also cancels its open subtasks. A terminal state that sits outside
the working statuses, and is reversible by reopening.
_Avoid_: Deleting, closing, rejecting

**Task type**:
A per-project classification for tasks — Feature, Bug, Chore. Configurable per
project, not a fixed set.

**Doc**:
A statusless knowledge page in a project — specs, lore, system notes — with a
canonical home instead of being smeared across task descriptions. A doc is either a
**draft** (visible only to members who may edit docs) or **published** (visible to
the project).
_Avoid_: Page, article, wiki page

**Note**:
A personal, user-owned capture. Unlike a doc, a note is private to its author by
default and need not belong to a project at all; it may optionally be attached to
one and, separately, made public to that project's members. A note can later be
converted into a task.
_Avoid_: Memo, scratchpad

## Naming and linking

**Reference**:
The human-readable identifier of an item — `KAN-42` for a task, `KAN-D3` for a doc.
This is the primary sense of the word: what you type in the palette, what the API
and MCP tools take as a parameter, what a person says out loud.

**Cross-reference**:
A link from one item to another, written inline in its content. Bidirectional: an
item has the things it references, and the things that reference it. Say
"cross-reference" when the link is meant, not the identifier.

**Dependency**:
An ordering constraint between tasks: a task is **blocked by** the tasks it depends
on and **blocks** the tasks depending on it. A task is blocked while any blocker is
incomplete. Distinct from a cross-reference — a dependency constrains *when* work can
proceed, a cross-reference only says "see also".
_Avoid_: Link, relation

**Tag**:
A project-scoped label applied to tasks and docs. A tag may carry **synonyms** —
alternative names that find it in search and pickers without becoming separate tags.

**Comment**:
A message written on a task or doc by a member. Comments carry the same rich text,
mentions and cross-references as any other content.

**Mention**:
Naming a user inside content (`@name`), which links to them and subscribes them to
the item's activity.

## Activity

**Activity**:
The user-facing history of what happened to an item or in a project — the readable
surface over the recorded audit events.

**Audit event**:
The durable record of something that happened, categorised (content, authz, access,
…) and never rewritten. Activity is one view over these; other sinks may consume the
same stream.

## Variables *(designed, not yet built — KAN-454)*

The terms below are settled vocabulary for work that does not exist yet. They are
here so the language is fixed before the code is written; see
`docs/adr/0001-project-variables.md`.

**Variable**:
A named, project-scoped value standing in for a fact that appears in many places or
is not yet decided — `main_protagonist` → "Robin Hood". The variable is the single
source of truth for that fact; the places it appears merely name it.
_Avoid_: Placeholder, macro, token, constant

**Value**:
What a variable currently stands for. A variable may have no value yet — a normal
state, not an error, and the whole point of the feature during early planning.
_Avoid_: Content, setting

**Usage**:
One occurrence of a variable inside authored content. Usages are written by the
author and never rewritten when a value changes: the content keeps naming the
variable, and shows the value at the moment it is read.
_Avoid_: Reference, cross-reference, instance, occurrence

> A usage is neither a **reference** (an item's identifier) nor a
> **cross-reference** (a link between items). Both words are already taken; keep
> them out of variable code and UI.

**Unset variable**:
A variable that exists but has no value. Its usages still render, showing the
variable's name rather than a value — a visible hole in the prose, which is the
point during early planning.

**Unknown name**:
A name used in content that matches no variable in the project — written before the
variable was created, or left behind when one was deleted. It renders like an unset
variable and is listed for the project, so it can be resolved rather than lost.
