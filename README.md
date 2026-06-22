# Kanbrio

![Tests](https://github.com/Fanmade/kanbrio/actions/workflows/tests.yml/badge.svg)
![Coverage](https://img.shields.io/endpoint?url=https://gist.githubusercontent.com/Fanmade/89b10cbc79557b748b8f50d2955dd9f6/raw/coverage.json)

A minimalist, invitation-only Kanban project-management tool. Organize work as
**Projects → nestable Tasks** (a project has tasks; tasks have subtasks), with
human-readable scoped URLs, a drag-and-drop board, comments, attachments, an
audit trail, and per-project notifications.

Built on Laravel with Livewire and Flux UI. English and German out of the box.

> ⚠️ **Early development** — Kanbrio is still under active development and is **not
> yet considered production ready**. Expect breaking changes, and use it in
> production at your own risk.

## Features

- **Projects & nestable Tasks** — a project contains tasks, and tasks nest into
  subtasks (up to a configurable depth, default three) with flat per-project task
  numbers. A task page shows its place in the tree, its subtasks, and a progress
  rollup over the whole subtree. Closing a parent can cascade to its open subtasks
  (ask / always / never), starting a subtask pulls the parent into progress, and
  completing the last open subtask can prompt to close the parent (ask / always /
  never).
- **Focused item views** — the project and task pages keep the description
  front and centre, with metadata (status, priority, assignees, dependencies,
  dates) gathered in a compact side rail. Status and priority are badges that open
  a dropdown to change them, and editing controls stay tucked away until needed.
- **Readable scoped URLs** — `/{SHORT}` for a project, `/{SHORT}/board` for its
  board, and `/{SHORT}-{n}` for a task (e.g. `/ABC`, `/ABC/board`, `/ABC-42`).
- **Command palette** (`⌘K` / `Ctrl+K`) — search projects and tasks by
  title or tag, jump straight to a typed reference, and run quick actions
  including creating a task from anywhere.
- **Create task dialog** — one dialog for creating tasks, opened from the board,
  a project, a parent task or the command palette. Pick the target project and an
  optional parent task (offered only where nesting stays within the depth limit) —
  both preselected from the page you opened it on — set the title, a rich-text
  description, priority, status and due date, and add tags and assignees inline. A "Create another" option keeps the dialog open — retaining
  the project, parent, priority and status — to add several tasks in a row. After
  creating, a dismissible toast links straight to the new task.
- **Kanban board** — drag-and-drop across the four statuses (Planned, ToDo,
  In progress, Done), per project or globally across every project you can see.
  Dragging is smoothly animated with highlighted drop targets and works on touch;
  each card also has a keyboard-accessible "Move to" menu. Cards keep the order
  you arrange them in within each column.
- **Project overview** — each project page lists its top-level tasks grouped into
  open and completed, and shows every root task's direct subtasks as quick links to
  drill straight into them.
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
  status and is fully reversible.
- **Dashboard** — per-status task counts, a 14-day completion chart, and a "My
  tasks" list for picking the next thing to work on: your in-progress and to-do
  tasks plus unassigned to-do tasks across your projects (work assigned to others
  is hidden), in-progress first.
- **Multi-assignee** tasks for pairing and ensemble work.
- **Profile avatars** — upload a profile picture (cropped to a square) from
  profile settings; it shows wherever you appear — assignees, comment authors and
  member lists — with your initials as the fallback when you have none.
- **Comments** with one-level replies, editing, and soft-delete tombstones, written
  with the same rich-text editor as descriptions. The whole section can be collapsed,
  remembered per user.
- **Attachments** — drag files onto a description to upload them, with inline
  image and PDF thumbnails. Files above the size limit are rejected with a
  clear message.
- **Tags** — label tasks with color-coded tags, shown as badges with
  a colored dot. Add one from a searchable list of your most-used tags, or create
  a new tag on the spot and pick its color.
- **Priorities** — five levels (Lowest, Low, Medium, High, Highest; Medium is the
  default) on tasks, with new subtasks inheriting their parent task's priority.
  Board columns are ordered by priority and can be filtered to a single level.
- **Due dates** on tasks, highlighted on the board when overdue.
- **Dependencies** — mark a task as blocked by, or blocking, another task (by
  reference). Blockers and blocked items are listed on the task view,
  and a card is flagged "Blocked" on the board while any blocker is unfinished.
  Self-links and cycles are rejected. Available in the UI and through the MCP tools.
- **Notifications** — subscribe per project (assignment auto-subscribes you),
  manage everything from a dedicated page, unread badge in the header.
- **Rich-text descriptions & comments** — task/project descriptions and comments are
  edited with a Flux/Tiptap WYSIWYG editor (stored as sanitized HTML) supporting
  headings, lists, links, quotes, code and inline images pasted or dropped straight in.
- **Activity log** — polymorphic audit trail of creations, status, priority,
  assignment, tag and dependency changes, plus cancellations and reopenings,
  naming what changed (which assignees, which tags, which dependency, the cancel
  reason) and noting when an action was performed via an API/MCP token (showing the
  token name). Collapsed by default; the open/closed state is remembered per user.
- **Invitation-only onboarding** via signed, expiring email links (public
  registration is disabled).
- **User administration** — an admin-only area (gated by the `manage-users`
  permission) to review every account, grant or revoke permissions, resend or
  revoke pending invitations, and deactivate (reversible, blocks sign-in) or
  remove accounts. Removed accounts are soft-deleted with their assignments
  dropped; comments they wrote are kept as the work of a "deleted user".
- **Authorization** via native Gates (`create-projects`, `invite-users`,
  `create-api-tokens`, `manage-users`) and Policies that cascade through project
  membership.
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
  and any cancellation reason, and agents can read attachments — including inline
  description images — by their id.
- **Localization** — English and German, defaulting to the browser language with
  a switcher in Appearance settings.

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
