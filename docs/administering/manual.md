# Administrator manual

For whoever runs a Kanvigo instance: getting it up, keeping it running, and the
settings worth knowing about. It assumes you are comfortable with a Laravel
deployment; the [developer docs](../developing/testing.md) cover working on the
code itself.

> Kanvigo is not production-hardened yet. Read [Operational
> checklist](#operational-checklist) before putting real data in it.

## Before you install

- PHP 8.5 with the `dom`, `gd`, `imagick`, `libxml`, `mbstring` and `zip`
  extensions. Ghostscript (`gs`) is optional — without it, PDF attachments fall
  back to a generic icon instead of a first-page thumbnail.
- Node and npm, to build the front end.
- **A Flux UI Pro licence.** `composer install` pulls from a private repository,
  so the application cannot be built without one. This is the hard blocker for
  self-hosting.
- A database. SQLite is the default and is what the test suite runs on;
  PostgreSQL and MySQL work through the standard `DB_*` variables.

## First run

`composer setup` installs dependencies, copies `.env.example` to `.env`,
generates the application key and the Passport keys, creates the SQLite file
when that is the configured connection, migrates, and builds the assets.

**It does not seed.** Seeding is the separate step that creates your first
administrator, and it is deliberately not folded in: locally it also loads demo
content, which is not idempotent, so a second `composer setup` would duplicate
it.

### The first administrator

The seeder reads three variables and creates one account from them:

```dotenv
ADMIN_NAME="Your Name"
ADMIN_EMAIL=you@example.com
ADMIN_PASSWORD=a-real-password
```

That account gets every account permission. Two behaviours to know: seeding
**fails with an error** if either variable is missing, rather than leaving you
with an instance nobody can sign in to — and if a user with that email already
exists the seeder says so and leaves it alone, so re-seeding never resets a
password.

Seed with `php artisan db:seed`. It is safe to repeat: an existing administrator
is left alone and its password is not reset. Do not reach for `migrate:fresh
--seed` — it **drops every table** first, and Laravel's destructive-command guard
only applies when `APP_ENV=production`, so a staging box on `local` will happily
wipe itself.

### Demo data

A demo seeder fills the instance with two example projects, a fictional team,
tasks, comments, notes and docs. It runs **only when `APP_ENV=local`**, and if
no configured administrator exists it creates its own `admin@example.com` with a
random password and full permissions.

The practical consequence: never run a real instance with `APP_ENV=local`.

## Keeping it running

Kanvigo needs two background processes.

**The scheduler** — `php artisan schedule:run` from cron, every minute:

| Command | When | What it does |
| --- | --- | --- |
| `audit:outbox:drain` | every minute | Ships audit events to queued sinks |
| `backup:clean` | 01:00 | Applies backup retention |
| `backup:run` | 01:30 | Takes the backup |
| `tasks:auto-archive` | daily | Archives Done tasks past the threshold |
| `attachments:prune-inline` | daily | Removes orphaned inline uploads |
| `audit:outbox:prune` | daily | Deletes dispatched audit rows past retention |
| `model:prune` (notifications) | daily | Deletes dismissed notifications after 30 days |

Without it: no backups, nothing auto-archives, orphaned attachments accumulate,
and queued audit sinks never receive anything. The default audit sink is
synchronous, so the in-app activity feed keeps working regardless.

**A queue worker** — `QUEUE_CONNECTION=database` by default. Only the
variable-usage indexing jobs are queued, so without a worker variable search and
the rename-rewrite go stale; mail and notifications are unaffected because both
are synchronous. Run `php artisan queue:restart` on every deploy, or workers
keep executing the old code.

## Mail

Set `MAIL_MAILER` to something real. The default is `log`, which means
invitations never leave the machine — they land in `storage/logs/laravel.log`.

Kanvigo sends invitations, and Fortify sends password resets, email verification
and two-factor mail. Invitation mail is sent **synchronously**, so a broken SMTP
configuration surfaces as an error on the page rather than a failed job.

`APP_URL` matters more than usual: invitation links and signed attachment URLs
are absolute and signature-checked, so a wrong value produces links that 404 or
fail verification. Invitations expire after seven days, which is not
configurable.

**There is no email for in-app notifications or mentions.** Those are delivered
inside the application only, and no configuration turns them into mail.

## Settings worth knowing

Most of these are absent from `.env.example` — the defaults live in `config/`.

**`config/kanvigo.php`**

| Variable | Default | Effect |
| --- | --- | --- |
| `KANVIGO_TASK_MAX_DEPTH` | 3 | How deep subtasks may nest |
| `KANVIGO_AUTO_ARCHIVE_DAYS` | 30 | Days a Done task waits before archiving; projects may override, `0` disables |
| `KANVIGO_LIVE_UPDATES_INTERVAL` | 15 | Seconds between board refreshes (polling — there is no websocket setup) |
| `KANVIGO_EXPORT_IMAGE_MAX_EDGE` | 1024 | Longest edge for images inlined into an export |
| `KANVIGO_EXPORT_INLINE_BUDGET` | 5 MiB | Past this, export images degrade to links |
| `KANVIGO_EXPORT_MAX_PROJECT_ITEMS` | 2000 | Whole-project exports above this are refused — they are built in the request |

**`config/attachments.php`**

| Variable | Default | Effect |
| --- | --- | --- |
| `ATTACHMENTS_DISK` | `local` | Where files go. Each attachment records its own disk, so changing this does not break existing files |
| `ATTACHMENTS_DIRECTORY` | `attachments` | Path within the disk |
| `ATTACHMENTS_MAX_SIZE` | 12288 (KB) | Per-file cap. Raising it means also raising Livewire's temporary-upload rule, PHP's `upload_max_filesize` and `post_max_size`, and any reverse-proxy body limit |
| `ATTACHMENTS_SIGNED_URL_TTL` | 30 (minutes) | Lifetime of signed download links |
| `ATTACHMENTS_GHOSTSCRIPT` | `gs` | Binary used for PDF thumbnails |

**`config/audit.php`** — `AUDIT_OUTBOX_RETENTION_DAYS` (default 30, `0` keeps
forever) and `AUDIT_PII_TOKEN_SALT`, which falls back to the application key.
Rotating that salt invalidates every pseudonymous token already published; that
is deliberate, and it is the crypto-shredding lever.

Out of the box `SESSION_DRIVER`, `CACHE_STORE` and `QUEUE_CONNECTION` all point
at the database, so with SQLite a single file carries sessions, cache, queue and
data. Fine for a small instance, a write-contention risk beyond that.

## Accounts and access

### Onboarding

Registration is closed; there is no sign-up page. People join by invitation from
`/invite`, which needs the `invite-users` permission. See
[Inviting users](../using/inviting-users.md).

### Account permissions

Six permissions govern what an account may do outside any single project:

| Permission | Gates |
| --- | --- |
| `create-projects` | Creating a project (the creator becomes its owner) |
| `access-all-projects` | Seeing every project — **visibility only**; contributing still needs a project role |
| `invite-users` | Sending invitations |
| `create-api-tokens` | Minting personal API tokens |
| `manage-users` | User administration, invitation resend/revoke, and half the audit-stream check |
| `manage-account-roles` | The account roles page |

Grant them per user as chips in **user administration**, or bundle several under
a name on the **Account roles** page (`/admin/roles`) and assign that role. A
permission held through a role is shown as such on the user's row and is taken
away by removing the role, not by clicking the chip.

An administrator may only put permissions they hold themselves into a role, so a
role can never be used to hand out more than its author has.

### The system role

Under everything sits a **system role** that implicitly holds every permission
and reaches into every project. It exists so a fresh instance can be set up and
an emergency can be fixed. It is enabled by default
(`DELEGATED_PERMISSIONS_SYSTEM_ENABLED`); turn it off once real administrator
accounts exist.

### User administration

`/admin/users` needs `manage-users`. Opening it is itself recorded in the audit
trail, because the page lists everyone's contact details. From there you can:

- search accounts and see what each holds;
- toggle account permissions and assign named account roles;
- **deactivate** an account — it is signed out immediately, API requests start
  failing, and all of its API tokens are revoked — and reactivate it later;
- **remove** an account, which soft-deletes it: project access, assignments and
  subscriptions are detached, while authored comments survive attributed to a
  deleted user;
- resend or revoke pending invitations;
- manage a person's project memberships and their roles in each — this is
  additionally checked against `manage-members` on that project, so a user
  administrator cannot quietly add people to projects they do not govern.

You cannot deactivate or delete yourself, and you cannot revoke your own
`manage-users`.

## Project roles

Every project is seeded with four roles, each a child of the one above:
**owner → admin → member → viewer**.

| Role | Holds |
| --- | --- |
| viewer | Read the project and its activity log |
| member | Viewer, plus the full task set, tags, attachments, comments, docs, variables and content export |
| admin | Member, plus project settings, project delete, comment moderation and whole-project export |
| owner | Everything, including members, invitations and role management |

Note that **`manage-members`, `invite-members` and `manage-roles` are owner-only
by default** — an "admin" governs the project's settings, not its people.

Roles form a tree, and a role's permissions are bounded by its parent's. That is
the whole delegation rule: nobody can create a role more powerful than their
own, so handing out authority can never escalate it. A person may hold several
roles in a project and gets the union of them.

The seeded permission sets are **defaults, not a definition**: a project may
retune admin, member and viewer to taste, and a per-role "Reset to defaults"
restores them. The owner role is fixed. Role management only ever shows roles at
or below the actor's own, and nobody can edit a role they hold themselves.

## API tokens, MCP and OAuth

Personal tokens are minted by users themselves in settings, given
`create-api-tokens`. A token carries one access level — **read**, **read and
write**, or **audit event stream** — and may be restricted to a subset of the
owner's projects. The restriction is authoritative and is enforced before any
policy runs, so an out-of-scope project is denied outright.

The REST API lives under `/api/v1`, throttled to 60 requests a minute. The
interactive API reference at `/docs/api` is available in local development only.

The MCP server at `/mcp` exposes roughly thirty tools for AI assistants, on the
same authentication and throttle. Clients like Claude Desktop connect over
OAuth:

- allowed redirect targets are listed in `config/mcp.php` — add your own client's
  domain there, and uncomment the private-use schemes if you use an editor-based
  client;
- the consent screen lets the person restrict the connection to chosen projects;
- authorized applications are listed and revoked in each user's API-token
  settings;
- **Passport keys must exist and be readable.** `composer setup` tolerates a
  failure when generating them, so check for `storage/oauth-private.key` and
  `storage/oauth-public.key` before wondering why OAuth is broken.

## The audit trail

Every audited action is written into a transactional outbox as part of the
action itself — if the action rolls back, no audit record is left — and then
fanned out to **sinks**. The built-in sink is the product's own activity feed,
which is synchronous and needs no configuration. Others can be added, including
an optional hash-chained ledger; [the audit layer page](../developing/audit.md)
is the reference.

What is covered: content changes, authentication events, authorization and
membership changes, invitation and API-token lifecycles, account
deactivation/deletion, and a deliberate slice of *read* events — reading the
audit stream, viewing a member's contact details, downloading an attachment,
opening the user directory. Each event carries the actor, their IP and user
agent, which surface it came from (UI, MCP, REST) and the token used.

Queued sinks are shipped once a minute in strict order, at least once; a row is
only marked delivered when every sink accepted it, and failures stay pending and
retry. Pruning deletes **only delivered** rows past
`AUDIT_OUTBOX_RETENTION_DAYS`; pending rows are never pruned.

Consuming the trail externally needs both the `manage-users` account permission
and a token with the audit ability — the endpoint is `GET /api/v1/audit-events`.

Two things to plan around: the activity feed table has **no pruning at all** and
grows without bound, and undismissed notifications are likewise kept forever.

## Backups

Backups are scheduled out of the box — cleaned at 01:00, taken at 01:30 — using
spatie/laravel-backup. The defaults are a starting point, not a backup strategy.
Fix these before relying on them:

- **The destination is the `local` disk**, meaning `storage/app` — on the same
  machine, inside the tree being backed up. Configure an off-site disk.
- **Archives are unencrypted** unless you set `BACKUP_ARCHIVE_PASSWORD`. The file
  backup includes `.env`, so an unencrypted archive contains your application
  key.
- The health-check configuration references an `s3` disk that does not exist by
  default, so backup monitoring misreports until you correct it.
- Uploaded attachments and avatars under `storage/app` are included in the file
  backup; the database is dumped from the configured connection, and with SQLite
  the database file is picked up by the file backup as well.

Retention defaults to all backups for 7 days, then daily for 16 days, weekly for
8 weeks, monthly for 4 months and yearly for 2 years. Backup notifications go to
`MAIL_FROM_ADDRESS`.

## Operational checklist

Do:

- generate `APP_KEY` before first use — the audit token salt falls back to it,
  and rotating it later invalidates pseudonymized data;
- set `APP_URL` to the real, externally reachable URL;
- set a real `MAIL_MAILER`;
- run cron for the scheduler *and* a queue worker, and `queue:restart` on deploy;
- make `storage/` and `bootstrap/cache/` writable by the web user;
- turn `LOG_LEVEL` down from `debug` and `APP_DEBUG` off;
- verify the Passport key files exist;
- run `php artisan storage:link` only if you move attachments or avatars to the
  `public` disk — the defaults are served through authenticated routes and do not
  need it.

Don't:

- run `migrate:fresh` on an instance with data in it — `db:seed` is the
  repeatable step;
- run a real instance with `APP_ENV=local` — that is what triggers the demo
  seeder;
- leave the system role enabled once proper administrator accounts exist;
- raise the attachment size limit without raising the PHP and proxy limits to
  match;
- enable the permission package's own gate registration — the application
  registers its own, which enforces API-token project scoping first.

On PostgreSQL, note that the user search in administration uses `like`, which is
case-sensitive there and case-insensitive on SQLite and MySQL.
