# Kanvigo

![Tests](https://github.com/Fanmade/Kanvigo/actions/workflows/tests.yml/badge.svg)
![Coverage](https://img.shields.io/endpoint?url=https://gist.githubusercontent.com/Fanmade/89b10cbc79557b748b8f50d2955dd9f6/raw/coverage.json)

A minimalist, invitation-only Kanban project-management tool. Organize work as
**Projects → nestable Tasks** (a project has tasks; tasks have subtasks), with
human-readable scoped URLs, a drag-and-drop board, comments, attachments, an
audit trail, and per-project notifications.

Built on Laravel with Livewire and Flux UI. English and German out of the box.

> ⚠️ **Early development** — Kanvigo is still under active development and is **not
> yet considered production ready**. Expect breaking changes and use it in
> production at your own risk.

## Features

- **Projects & nestable Tasks** — a project contains tasks, and tasks nest into
  subtasks (up to a configurable depth, default three) with flat per-project task
  numbers. A task page shows its place in the tree, its subtasks, and a progress
  rollup over the whole subtree. A task can be moved under a different parent or
  detached to the top level. Closing a parent can cascade to its open subtasks
  (ask / always / never), starting a subtask pulls the parent into progress, and
  completing the last open subtask can prompt to close the parent (ask / always /
  never).
- **Focused item views** — the project and task pages keep the description
  front and centre, with metadata (status, priority, assignees, dependencies,
  dates) gathered in a compact side rail. Status and priority are badges that open
  a dropdown to change them — with one-click buttons beside the status to step it
  forward or back along the progression (Planned ⇄ To do ⇄ In progress ⇄ Done) —
  and editing controls stay tucked away until needed.
- **Readable scoped URLs** — `/{SHORT}` for a project, `/{SHORT}/board` for its
  board, and `/{SHORT}-{n}` for a task (e.g. `/ABC`, `/ABC/board`, `/ABC-42`).
- **Command palette** (`⌘K` / `Ctrl+K`) — search projects and tasks by
  title or tag, jump straight to a typed reference (`PROJ-42` or the compact
  `PROJ42`), find tasks by a bare number across your projects (prioritizing the
  one you're viewing), and run quick actions including creating a task from anywhere.
- **Create task dialog** — one dialog for creating tasks, opened from the board,
  a project, a parent task or the command palette. Pick the target project and an
  optional parent task (offered only where nesting stays within the depth limit) —
  both preselected from the page you opened it on — set the title, a rich-text
  description, priority, status, an optional type and due date, and add tags and
  assignees inline. A "Create another" option keeps the dialog open — retaining
  the project, parent, priority and status — to add several tasks in a row. After
  creating, a dismissible toast links straight to the new task.
- **Kanban board** — drag-and-drop across the four statuses (Planned, ToDo,
  In progress, Done), per project or globally across every project you can see.
  Dragging is smoothly animated with highlighted drop targets and works on touch;
  each card also has a keyboard-accessible "Move to" menu. Cards keep the order
  you arrange them in within each column. Each column has its own search — a
  compact icon that expands to a search box — to filter that column's cards by
  title or reference. The board refreshes
  automatically as others make changes — a per-user "Live updates" toggle turns
  this off — and never refreshes mid-drag.
- **Project overview** — each project page lists its top-level tasks and shows
  every root task's direct subtasks as quick links to drill straight into them. The
  task list is collapsible (collapsed by default) and filterable — closed (Done &
  Canceled) and archived tasks are hidden until you opt in, and it can be narrowed
  by priority, tags or assignees — selecting several at once, matching any or all
  of them. The lists and comments update automatically as others make changes
  (Live updates).
- **Completion progress bars** — any task with subtasks shows a progress rollup
  based on the share of its descendant tasks done, on the project overview (per
  root task) and on the task's detail page.
- **Cancellation** — abandon a task with a reason (Won't fix, Duplicate or
  Deprecated) and an optional note, instead of deleting it. Cancelling a task also
  cancels its open subtasks. Canceled tasks keep their full history and are taken
  off the board and out of active counts, but stay visible on the project overview;
  reopening one returns it to Planned.
- **Archiving** — archive finished tasks to clear them from the
  board and project overview without deleting them. Archived items are hidden by
  default and revealed with a "Show archived" toggle; archiving keeps a task's
  status and is fully reversible. Tasks left in **Done** beyond a threshold are
  auto-archived by a daily job — configurable per project (and a global default),
  or set to 0 to turn it off for a project.
- **Dashboard** — per-status task counts, a 14-day completion chart, and a "My
  tasks" list for picking the next thing to work on: your in-progress and to-do
  tasks plus unassigned to-do tasks across your projects (work assigned to others
  is hidden), in-progress first.
- **Quick notes** — jot a personal note from anywhere (the command palette, the
  dashboard Notes panel, or the dedicated **Notes** page in the sidebar), with a
  rich-text body and inline images. The Notes page lists all your notes with
  create, edit, convert and delete in one place, search and filter by project, pin
  favourites to the top, and reorder them by hand. A note is private to you by
  default; attach it to a project you belong to and you can make it public, so
  that project's members can read it in the project's Notes section (read-only —
  only the owner edits, re-shares or deletes). Convert a note into a task in one
  step: the task takes the note's title and body, and the note keeps a
  "Converted → PROJ-N" link. Also available through the MCP tools. See
  [docs/quick-notes.md](docs/quick-notes.md).
- **Multi-assignee** tasks for pairing and ensemble work, with a one-click "assign
  to me" on the task page and in the create-task dialog.
- **Profile avatars** — upload a profile picture (cropped to a square) from
  profile settings; it shows wherever you appear — assignees, comment authors and
  member lists — with your initials as the fallback when you have none.
- **User profiles** — every user has a profile page showing their avatar, the
  projects you share with them, and their recent activity in projects you can see.
  Mentions, comment authors and member lists link to it. A profile is visible only
  to people who share a project with the user (and to admins who can see every
  project).
- **Comments** with one-level replies, editing, and soft-delete tombstones, written
  with the same rich-text editor as descriptions. The whole section can be collapsed,
  remembered per user. New comments and activity from others appear automatically
  while "Live updates" is on, without interrupting a reply you're typing.
- **Attachments** — drag files onto a description to upload them, with inline
  image and PDF thumbnails. Files above the size limit are rejected with a
  clear message.
- **Tags** — label tasks with color-coded tags, scoped to their project and
  shown as badges with a colored dot, or an optional icon in the tag's color. Add
  one from a searchable list of the project's most-used tags, or create a new tag
  on the spot and pick its color and icon. A per-project tag page lets members
  create tags as well as rename, recolor and set the icon of tags (renaming onto an existing tag merges
  the two), while admins and owners can delete them or merge several duplicates
  into one chosen tag — re-tagging affected tasks before the others are removed,
  and optionally keeping the merged names as synonyms of the survivor. Each tag
  can carry synonyms — alternative names it is also found by when searching, so a
  "Research" tag turns up when you type "evaluation". Every change is recorded in
  the activity log.
- **Task types** — classify a task by an optional type, scoped to its project and
  shown as a colored badge with an optional icon on its board card. Each project starts with a
  sensible default set (Feature, Bug, Chore); pick one when creating a task or
  change it later from the task page, and filter the board to a single type.
  Admins can add, rename, recolor, re-icon, reorder and delete a project's types
  from its task-types page; deleting a type leaves its tasks untyped.
- **Priorities** — five levels (Lowest, Low, Medium, High, Highest; Medium is the
  default) on tasks, each shown as a coloured, icon'd badge, with new subtasks
  inheriting their parent task's priority. Pickers and filters list them highest
  first. Board columns are ordered by priority and can be filtered to a level.
- **Due dates** on tasks, highlighted on the board when overdue.
- **Relationships** — link a task to another (by reference) with a typed
  relationship: blocks / blocked by, relates to (symmetric), duplicates /
  duplicated by, clones / cloned by, or causes / caused by. Only blocking links
  affect scheduling — a card is flagged "Blocked" on the board while any blocker
  is unfinished, and blocking cycles (and self-links) are rejected; the other
  types are purely informational. Relationships are grouped by type on the task
  view, and available through the REST and MCP APIs.
- **Notifications** — subscribe per project (assignment auto-subscribes you),
  manage everything from a dedicated page, unread badge in the header.
- **Mentions & references** — in any description or comment, type `@` to mention a
  project member (they are notified and auto-subscribed to the item) and `#` to
  reference a task, picked from an autocomplete of the project's members and tasks.
  Mentions render as links to the member's profile, and references as links to the
  task — wherever the content is shown — each with a hover preview card: a mention
  shows the user's name, avatar and their role in the project; a reference shows the
  task's title, status, priority, assignees and progress.
- **Rich-text descriptions & comments** — task/project descriptions and comments are
  edited with a Flux/Tiptap WYSIWYG editor (stored as sanitized HTML) supporting
  headings, lists, links, quotes, code and inline images pasted or dropped straight in.
- **Activity log** — polymorphic audit trail of creations, status, priority,
  assignment, tag and dependency changes, plus cancellations and reopenings,
  naming what changed (which assignees, which tags, which dependency, the cancel
  reason) and noting when an action was performed via an API/MCP token (flagged
  generically, without revealing the token's private name). Collapsed by default;
  the open/closed state is remembered per user. Relative times ("5 hours ago")
  reveal the exact date and time on hover, here and on comments. Any entry can be
  discussed: **Discuss** drops a reference to it into the comment composer (a
  comment may reference several entries, even ones on other tasks), and the posted
  comment shows a card per reference linking back to the entry — opening the feed
  and scrolling to it, navigating across tasks where needed.
- **Invitation-only onboarding** via signed, expiring email links (public
  registration is disabled).
- **User administration** — an admin-only area (gated by the `manage-users`
  permission) to review every account (including how many pending invitations
  each has sent), grant or revoke permissions, manage which projects each user
  belongs to and their role, resend or revoke pending invitations, and deactivate
  (reversible, blocks sign-in) or remove accounts. Removed accounts are
  soft-deleted with their assignments dropped; comments they wrote are kept as the
  work of a "deleted user".
- **Project roles & membership** — each member holds one or more per-project
  roles, and what they may do is the union of those roles' permissions (so you
  can mix two custom roles on the same person). Four roles are seeded
  — owner (the project's creator), admin, member and a read-only viewer — and
  anyone with the manage-roles permission can define **custom roles** for a
  project. Each project has exactly one owner; ownership is never handed out as a
  second role. Every custom role is created under a chosen **parent role** and
  may hold only a subset of that parent's permissions, so delegation can never
  escalate beyond it. What a member may do follows from the permissions their
  roles hold: every member can contribute — create and work on tasks, comment,
  attach files — while editing the project's settings (title, short name,
  description) and deleting it require the matching permission, held by admins,
  the owner, and any custom role granted it. From the project page a manager
  adds and removes a member's roles as chips, **edits a custom role's permissions
  in place**, and can prefill a new role by **copying the permissions of an
  existing role** (bounded by the chosen parent) as a starting point. A manager
  only ever sees the roles they hold and those beneath them — never a parent role
  or the system role. Managing a user's
  memberships from the user-administration area requires the member-management
  permission on the project in question (or the system role), so account
  administration alone does not grant the run of every project.
- **Cross-project access** — the account-level `access-all-projects` permission
  (grantable from user administration) lets staff see and open every project,
  whether or not they are a member. It is a read/visibility grant only: such a
  user's project list shows all projects, but contributing to or administering a
  project still requires a scoped role on it.
- **Authorization** via native Gates (`create-projects`, `access-all-projects`,
  `invite-users`, `create-api-tokens`, `manage-users`) and Policies that resolve
  project access through inheritance-based, per-project delegated permissions.
- **API tokens** — permitted users mint personal Sanctum tokens (read-only or
  read & write) for MCP/API access and revoke them from Settings.
- **MCP server** — a Model Context Protocol endpoint at `/mcp`, secured by a bearer
  token, that lets AI agents work with the projects and tasks the token's owner can
  access. Read tools (list/inspect) work with any token and surface each item's
  dependencies (what blocks it, what it blocks, and whether it is currently
  blocked); write tools (create/update tasks, cancel or reopen tasks, create
  projects, add comments, link/unlink dependencies) require a token with write
  access. Descriptions and comment bodies are exchanged as HTML (sanitized to an
  allow-list on write). Inspecting a project or task also returns its comment thread
  and any cancellation reason, and agents can read attachments by their id —
  inline description images, audio, and text-based files (logs, JSON, XML, CSV, …)
  returned inline as text, with a byte offset to page through large files. Personal notes have their own tools (create,
  list, get, update and convert-to-task), referenced by a numeric note id.
- **REST API** — a versioned, documented HTTP API under `/api/v1` for projects,
  tasks and comments, authenticated with the same personal access tokens (Bearer;
  read tokens for the GET endpoints, write tokens to create and update). Responses
  are consistent JSON resources, lists are paginated, and access is scoped to the
  caller's projects exactly like the rest of the app. Interactive OpenAPI docs live
  at `/docs/api` (local only). See [docs/api.md](docs/api.md).
- **Localization** — English and German, defaulting to the browser language with
  a switcher in Appearance settings.
- **Full-width layout** — an Appearance setting to let page content span the whole
  screen instead of the default centered reading column, for large displays.

## Tech stack

- PHP 8.4+ / Laravel 13
- Livewire 4 + Flux UI Pro
- Laravel Fortify (login, email verification, 2FA, passkeys)
- Tailwind CSS 4
- SQLite (default), Vite
- Pest 4, Larastan, Pint

> **Note:** This project uses [Flux UI Pro](https://fluxui.dev), a commercial
> package. A valid Flux Pro license is required to run `composer install`.

## Getting started

Requirements: PHP 8.4+, Composer, Node.js, and a Flux Pro license.

```bash
# Install dependencies, create .env, generate the key, migrate, and build assets
composer setup

# Seed the database (creates the configured admin and local demo data)
php artisan migrate:fresh --seed

# Run the full dev stack (server, queue, logs, Vite)
composer dev
```

The app is served at <http://localhost:8000>.

### Default admin

The seeder creates an administrator (with all permissions) only when you set
both credentials in your `.env`:

```dotenv
ADMIN_NAME="Admin"        # optional, defaults to "Admin"
ADMIN_EMAIL=you@example.com
ADMIN_PASSWORD=change-me
```

The admin can create projects and invite users. In `local`, the `DemoSeeder`
also populates example projects and tasks (with nested subtasks) (and seeds its
own demo admin if none is configured).

## Inviting users

Public registration is disabled by design. Users with the `invite-users`
capability send a signed invitation link by email (use the bottom of the
sidebar). With `MAIL_MAILER=log` (the default), the link is written to
`storage/logs/laravel.log`. Opening the link lets the invitee set their name and
password, after which they land on the security setup page.

## Testing & quality

The full quality gate runs Pint, Larastan, and Pest:

```bash
composer test
```

Individual checks:

```bash
composer lint          # Pint (apply fixes)
composer types:check   # Larastan / PHPStan
php artisan test       # Pest
composer test:coverage # Pest with line coverage + minimum threshold
```

CI measures line coverage on every run, fails if it drops below the configured
threshold, and publishes the current level to the coverage badge above.

Browser tests (Pest 4 + Playwright) live in `tests/Browser` and run as a
separate suite so the default gate stays fast and Playwright-free:

```bash
composer test:browser
```

They require the Playwright Chromium binary (`npx playwright install chromium`).

## Project layout

- `app/Livewire/` — class-based Livewire components (board, projects, tasks,
  comments, notifications, invitations).
- `app/Concerns/` — shared model traits (scoped numbering, activity logging,
  comments, tags, attachments, subscriptions).
- `app/Models/` — Project, Task, Comment, Attachment, Activity,
  Invitation, User.
- `app/Policies/` — per-resource authorization cascading through membership.
- `lang/` — English source strings inline; German in `de.json` and `de/`.
- `routes/web.php` — scoped routing for projects and tasks.
