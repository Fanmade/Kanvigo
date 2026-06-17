# Kanbrio

A minimalist, invitation-only Kanban project-management tool. Organize work as
**Projects → Stories → Tasks**, with human-readable scoped URLs, a drag-and-drop
board, comments, attachments, an audit trail, and per-project notifications.

Built on Laravel with Livewire and Flux UI. English and German out of the box.

## Features

- **Projects, Stories & Tasks** — three-level hierarchy with project-scoped story
  numbers and story-scoped task numbers.
- **Readable scoped URLs** — `/{SHORT}` for a project, `/{SHORT}{n}` for a story,
  `/{SHORT}{n}-{m}` for a task (e.g. `/ABC`, `/ABC1`, `/ABC1-3`).
- **Command palette** (`⌘K` / `Ctrl+K`) — search projects, stories and tasks by
  title or keyword, jump straight to a typed reference, and run quick actions.
- **Kanban board** — drag-and-drop across the four statuses (Planned, ToDo,
  In progress, Done), per project or globally across every project you can see.
- **Stories grouped on the board**, with completion progress bars on the project
  overview.
- **Multi-assignee** stories and tasks for pairing and ensemble work.
- **Comments** with one-level replies, editing, and soft-delete tombstones. The
  whole section can be collapsed, remembered per user.
- **Attachments** — drag files onto a description to upload them, with inline
  image and PDF thumbnails. Files above the size limit are rejected with a
  clear message.
- **Keywords** on stories and tasks.
- **Due dates** on stories and tasks, highlighted on the board when overdue.
- **Notifications** — subscribe per project (assignment auto-subscribes you),
  manage everything from a dedicated page, unread badge in the header.
- **Markdown** descriptions and comments.
- **Activity log** — polymorphic audit trail of creations, status changes, and
  assignment changes. Collapsed by default; the open/closed state is remembered
  per user.
- **Invitation-only onboarding** via signed, expiring email links (public
  registration is disabled).
- **Authorization** via native Gates (`create-projects`, `invite-users`,
  `create-api-tokens`) and Policies that cascade through project membership.
- **API tokens** — permitted users mint personal Sanctum tokens (read-only or
  read & write) for MCP/API access and revoke them from Settings.
- **MCP server** — a Model Context Protocol endpoint at `/mcp`, secured by a bearer
  token, that lets AI agents work with the projects, stories, and tasks the token's
  owner can access. Read tools (list/inspect) work with any token; write tools
  (create/update stories & tasks, create projects, add comments) require a token
  with write access.
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
also populates example projects, stories, and tasks (and seeds its own demo
admin if none is configured).

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
composer lint        # Pint (apply fixes)
composer types:check # Larastan / PHPStan
php artisan test     # Pest
```

Browser tests (Pest 4 + Playwright) live in `tests/Browser` and run as a
separate suite so the default gate stays fast and Playwright-free:

```bash
composer test:browser
```

They require the Playwright Chromium binary (`npx playwright install chromium`).

## Project layout

- `app/Livewire/` — class-based Livewire components (board, projects, stories,
  tasks, comments, notifications, invitations).
- `app/Concerns/` — shared model traits (scoped numbering, activity logging,
  comments, keywords, attachments, subscriptions).
- `app/Models/` — Project, Story, Task, Comment, Attachment, Activity,
  Invitation, User.
- `app/Policies/` — per-resource authorization cascading through membership.
- `lang/` — English source strings inline; German in `de.json` and `de/`.
- `routes/web.php` — scoped routing for projects, stories, and tasks.
