# Variables

A variable is a project-scoped stand-in for a fact that appears in many places or
is not decided yet: write `[main_protagonist]` in your text and it shows whatever
that variable currently stands for. The variable is the single source of truth for
that fact — the places it appears merely name it.

The feature exists for the half-decided phase of a project. A name you have not
chosen yet is a normal state, not an error.

## Writing a usage

Type `[` in any description, doc body or comment and a picker offers the project's
variables, filtered as you type; picking one inserts the plain text `[hero]`. You
can also just type it — a usage is ordinary text, not a special element, so it
survives copy-paste, an API write and an agent's drafting.

The editor always shows the raw `[hero]`, never the value: you are editing the
source, and substituted text would let the cursor land inside a marker you cannot
see. The value appears wherever the text is *read*.

Bracketed text that names no variable is left exactly as written, so `[1]`,
`[i]` and `[an aside]` are never mistaken for a usage. Text inside `code` and
`pre` blocks is left alone too.

## What a reader sees

| In the text     | The variable                | Rendered as                              |
| --------------- | --------------------------- | ---------------------------------------- |
| `[hero]`        | `hero` = "Robin Hood"       | Robin Hood, with a dotted underline and a hover card naming the variable |
| `[hero]`        | `hero`, no value yet        | **hero**, highlighted — a deliberate hole in the prose |
| `[sidekick]`    | no such variable            | `[sidekick]`, exactly as written         |

Changing a value changes every place at once and rewrites nothing: the stored text
keeps saying `[hero]` forever. That is what makes the placeholder reusable — a
value you baked into the text could never be changed again.

## Where variables work

Variables resolve in **prose**: task descriptions, doc bodies, comments and project
descriptions.

They do **not** resolve in titles. A title is an identifier — it appears in
notifications, search results, the activity log and API output — and must mean the
same thing everywhere and over time.

They also do not resolve in [quick notes](quick-notes.md). A note is yours and
belongs to no project, so it has no variable namespace to resolve against.

## Names

A name must be at least two characters, start with a letter, and use only lowercase
letters, digits, underscores and hyphens — `main_protagonist`, `ship-name`,
`weapon2`. Names are lower-cased for you and are unique within a project; two
projects can each have their own `hero` without interfering.

The rules are strict on purpose: they are what keeps footnote markers and ordinary
bracketed prose from ever colliding with a variable.

## Managing them

Open a project and choose **Manage variables** from its actions menu (or `⌘K` and
search for the variable by name *or* by value — "robin" finds
`main_protagonist = Robin Hood`, and lists the pages using it).

The page lists every variable with its value, description and how often it is used.
Each one can be inspected to see **where it is used** — the tasks, docs and pages
whose text names it — and its **history**: what it has stood for, and since when.
Names used in the text with no variable behind them are listed separately under
**Used but not defined**, with a one-click way to define them.

**Renaming** is the one operation that changes stored text: every `[old]` in the
project is rewritten to `[new]`, so no document is left pointing at a name that no
longer exists. You are asked to confirm, and told how many usages will change. The
documents go on saying the same thing — only the name they point at changes — so
each rewrite shows in that item's history as an ordinary edit.

**Deleting** a variable touches no text at all. Its usages stay written as
`[name]` and simply start rendering as unset, so recreating the variable with the
same name brings the value back.

## Permissions

One project permission covers all of it:

| Permission         | Allows                                                     |
| ------------------ | ---------------------------------------------------------- |
| `manage-variables` | Creating variables, setting values, renaming and deleting  |

There is no permission for *using* a variable — writing `[hero]` in prose is just
typing — and no create/edit/delete split, because a variable is a single fact.
Members, admins and owners hold it by default; viewers do not. Custom roles can
grant it on its own.

## AI / MCP

The MCP server exposes `list-variables`, `create-variable` and `update-variable`.
There is deliberately no delete tool: deleting a variable silently changes what
existing documents show.

`get-task` and `get-doc` return the body **exactly as stored**, `[name]` intact,
plus a `variables` sidecar listing the variables that content uses and their
values. Resolve names against that sidecar when reading, and leave the markers
alone when writing the body back — substituting on read would make a
read-edit-write round trip destructive, baking in values and deleting every usage.

Writing `[name]` in a body never creates a variable; a mistyped `[protagonsit]`
must not become permanent project vocabulary. Call `create-variable` when a name
should join it. `update-variable` can rename, which rewrites usages without a
confirmation step — there is nobody to ask — and reports how many items it changed.

## REST API

The [REST API](../developing/api.md) exposes the project's vocabulary and the same
sidecar:

| Method | Path                                    | Description                              |
| ------ | --------------------------------------- | ---------------------------------------- |
| `GET`  | `/projects/{short_name}/variables`      | The project's variables with their values |

The task and doc detail responses carry a `variables` array alongside the raw
content, for the same reason the MCP tools do.

## Trying it out

Create a variable without a value, write `[its_name]` into a task description, and
watch it render as a highlighted hole. Set the value later and every place that
names it says the new thing — with nothing to go back and edit.
