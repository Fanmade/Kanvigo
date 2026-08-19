# Developer manual

Orientation for working on Kanvigo: how to get it running, where things live,
what the conventions are, and where the seams for extending it sit. The deep
dives stay in their own pages — [REST API](api.md), [audit layer](audit.md),
[testing & quality](testing.md) — and are linked from here rather than repeated.

## Getting set up

Kanvigo is Laravel 13, Livewire 4, Flux Pro, Tailwind v4 and Pest.

**Flux Pro is a paid, private Composer repository.** Without credentials in your
`auth.json`, `composer install` fails outright — this is the first thing to sort
out.

```bash
composer setup      # install, .env, key, Passport keys, migrate, npm build
composer dev        # serve + queue:listen + pail + vite, all in one
```

With the default SQLite connection, create `database/database.sqlite` before the
first migrate — `composer setup` does not. Then seed once with
`php artisan db:seed`; on a local environment that also loads a demo project
set.

For the browser suite, install the browser once: `npx playwright install
chromium`.

## The quality gate

```bash
composer test        # config:clear + Pint + Larastan + Unit,Feature (parallel)
composer test:browser
composer test:all    # both of the above
composer check       # the full gate, what CI runs
```

`composer test` is the **whole fast gate**, not just tests — a formatting slip
fails it. Individual pieces: `composer lint` / `lint:check` (Pint, Blade
included), `composer types:check` (Larastan level 7), `composer test:coverage`
(enforces 90% line coverage).

Two rules about running tests, both of which cost people an afternoon otherwise:

- **Always scope to a suite.** `php artisan test --filter=Foo` with no
  `--testsuite` boots the Browser suite too, which looks like a hang. Use
  `php artisan test --compact --testsuite=Unit,Feature --filter=Foo`.
- **Never run the browser suite through bare artisan.** Pest's browser plugin
  leaks a Playwright server that holds the command's output pipe open; an
  interactive shell hides it, CI and agents block until timeout. `composer
  test:browser` reaps it. The reaper's odd shape is deliberate — see
  [testing & quality](testing.md).

## How the code is laid out

| Directory | Holds |
| --- | --- |
| `Actions/` | Business **writes** shared by more than one entry point |
| `Audit/` | The audit pipeline: manager, context resolver, sinks, PII redaction |
| `Authorization/` | Provisioning of roles and permissions, plus the label catalog |
| `Concerns/` | Traits shared by models *and* Livewire components |
| `Console/Commands/` | Artisan commands — scheduled work and backfills |
| `Contracts/` | Small capability interfaces models implement (`Mentionable`, `Referenceable`, …) |
| `Enums/` | Domain enums, each with a translated `label()` |
| `Http/` | Plain controllers (attachments, previews, locale), the versioned REST API, middleware, API resources |
| `Jobs/` | Queued work |
| `Livewire/` | Every UI component, pages and embedded alike |
| `Mcp/` | The MCP server, its tools and their shared traits |
| `Models/` | Eloquent models — invariants and lifecycle, not query logic |
| `Policies/` | One policy per model |
| `Queries/` | Named, reusable **reads** and projections |
| `Support/` | Domain services and value objects that are neither model nor action |

Don't add a new top-level folder under `app/` without agreeing it first.

### Where logic goes

- An **Action** is a write that more than one entry point needs, exposed as a
  single `handle()`. `CreateTask` exists because the board, the task page and the
  MCP tool must create tasks identically — if they each did it themselves they
  would drift.
- A **Query** is a named read or projection, also a `handle()`, returning a
  documented array shape or a collection. `TaskPreview` feeds the hovercard;
  `NamedAccountRoles` is shared by two screens. The `eloquent-query-classes`
  skill in `.ai/skills/` describes when a query earns extraction — a one-off
  lookup does not.
- A **model** keeps its own invariants: `Task::booted()` handles priority
  inheritance, board position, completion timestamps and cache invalidation.
  Models also declare `auditedFieldChanges()`, which is why every write path is
  audited at the model rather than at each call site.
- Anything that is none of those — resolvers, caches, substitutors, the search
  index — lives in `Support/`.

## The Livewire layer

Components are class-based: a class in `app/Livewire/<Area>/<Name>.php` and a
view at `resources/views/livewire/<area>/<kebab-name>.blade.php`. Pages are
registered with `Route::livewire(...)` in `routes/web.php`; embedded components
take their subject through `mount()` and are never routed.

Two habits worth copying from the existing components:

- **Computed properties.** Read them as properties (`$this->task`), never as
  methods (`$this->task()`) — the property form is what Livewire memoizes for the
  request; the method form re-runs the query and its eager loads on every call.
- **`#[Locked]` on identity.** Route-derived props like `$shortName` are locked so
  a tampered payload cannot repoint the component at another project, and
  computed properties re-authorize rather than trusting the mount.

`Flux::toast()` is always called with named arguments in signature order,
`text:` first — the second positional parameter is `$heading`, not `$variant`,
and a convention test enforces it.

## Authorization

Four pieces cooperate, and the order matters:

1. **The delegated-permissions package** supplies permissions, roles and the
   delegation bound — a child role's permissions are a subset of its parent's.
   Roles are scoped: to a project, or global.
2. **`ProjectRoleProvisioner`** seeds each project's `owner → admin → member →
   viewer` tree from its catalog. It is idempotent and never re-syncs an existing
   role, so a project's own edits survive.
3. **`AccountPermissionProvisioner`** does the account layer: one global role
   per `App\Enums\Permission` case, holding exactly that permission. Holding
   the role *is* the grant.
4. **A custom `Gate::before`** in `AppServiceProvider` replaces the package's
   own (which is switched off in config). It first hard-denies when an API token's
   project scope excludes the subject — skipping policies entirely — and only
   then grants on a held permission. The package's hook granted first, which
   would have let a project-restricted token authorize out-of-scope projects.

### The naming contract

Because `Gate::before` grants any ability that matches a held permission name,
**a policy ability must never be named the same as a permission**. If it is, the
gate grants it and your policy method becomes dead code. An
`AuthorizationContractTest` enforces this; policies bridge instead —
`TaskPolicy::close()` checks the `close-task` permission against the task's
project.

### Adding a permission

For a **project** permission: add the name to the provisioner's `GROUPS` and
`CATALOG`, add it to the role defaults it belongs in, give it a label and picker
label (and a description if it isn't obvious) in `PermissionCatalog`, add the
German strings, then write a policy method under a *different* ability name.

For an **account** permission: add a case to `App\Enums\Permission` with a
`label()` arm. The provisioner creates its global role on the next run.

## The data model

The models mostly read as you would expect; these are the parts that don't:

- **References are derived, not stored.** `PROJ-42` is an accessor over the
  project's short name and a per-project `task_number` assigned on create; docs
  use the same mechanism rendered as `PROJ-D3`.
- **Tasks nest** through an adjacency-list trait, with the depth cap and cycle
  guard enforced in the model.
- **Cross-references and mentions are indexes**, kept in step with the rich text
  by traits — write the text, and the links follow.
- **Variables substitute at read time** and are never baked into stored content
  ([ADR 0001](../adr/0001-project-variables.md)).
- **Soft deletes are the exception**, not the rule: users, notifications, docs
  and notes have them; tasks do not.
- **Activity rows are written only by the audit sink**, never directly.

`CONTEXT.md` is the glossary — the words this project uses, and the ones it
deliberately avoids. There is no Story or Epic entity; an epic is just a task
with children.

## Extension seams

**An audit sink** — implement the contract from the `kanvigo/audit-contracts`
package (`accepts()`, `record()`, `policy()`) and register the class in
`config/audit.php`. Registering it as `Class::class => ['redact' => true]` wraps
it so it only ever sees the PII-redacted copy. Sinks can be synchronous, queued
or fail-closed; a fail-closed sink requires every audited mutation to run inside
a transaction. [The audit layer](audit.md) is the reference, and
`ActivityLogSink` the worked example.

**An MCP tool** — extend the SDK's `Tool` in `app/Mcp/Tools/`, describe it with
attributes, validate through the request, reuse the traits in `Mcp/Concerns`
(write-access, pagination, URL exposure), and authorize with the same gates the
UI uses. Then **register it in the server's `$tools` array** — creating the
class is not enough — and update the server's instructions block if agents need
to know about it.

**A REST endpoint** — add to a controller under `Http/Controllers/Api/V1/`,
resolve references with the shared concern (out-of-scope items 404 rather than
403, so existence never leaks), return an API resource, and register it in
`routes/api.php` inside the authenticated group. Mutating routes carry the
write-token middleware. See [REST API](api.md).

**An export format** — deliberately *not* an interface
([ADR 0002](../adr/0002-export-has-no-format-abstraction.md)). Add an enum case,
write an exporter beside the existing ones, and add the arm to the renderer's
`match`. Be aware that filenames, subtree assembly and attachment handling
currently delegate to the Markdown exporter for every format; a format that
disagrees with those is the one that earns the abstraction.

**A settings page** — a Livewire component under `Livewire/Settings/`, a view
wrapped in the settings layout component, a route in `routes/settings.php`, a
nav entry, and translations. Add password confirmation middleware if it is
security-sensitive.

## Conventions

The rules live in `.ai/guidelines/` and are injected into the agent instruction
files. In short:

| Guideline | Rule |
| --- | --- |
| `browser-tests.md` | Target `data-test` selectors, never visible text; assert no JS errors; run through the composer script |
| `bug-reports.md` | Open a tagged bug task on the board before diagnosing |
| `condition-ordering.md` | Cheapest operand first in boolean chains — unless it is a guard |
| `feature-documentation.md` | Update README, changelog and docs in the same change as the feature |
| `flux-toasts.md` | Named arguments in signature order, `text:` first |
| `performance-tests.md` | Assert size-invariant budgets, never the mechanism |
| `static-closures.md` | `static` closures when they don't use `$this` — except factory, accessor and Pest closures |
| `visual-only-changes.md` | Presentational changes need no test; anything touching behaviour does |

Beyond those: explicit types everywhere, constructor property promotion, braces
always, PHPDoc over inline comments, named routes, factories in tests, and
`vendor/bin/pint --dirty` after touching PHP.

## Testing

Three suites — `Unit` (pure), `Feature` (the bulk: Livewire components, API,
MCP, policies) and `Browser` (Pest's Playwright integration).

**`RefreshDatabase` is opt-in for Feature tests.** Declare
`uses(RefreshDatabase::class);` at the top of any Feature test that touches the
database; a guard test scans for writes and fails the build if one forgets.
Browser tests get it globally.

The helpers in `tests/Pest.php` are worth knowing before writing a test:

- `joinProject($project, $user, $role)` — the canonical membership setup.
  Membership is **two** things, a pivot row *and* a role assignment; attaching the
  pivot alone produces a member who can see nothing.
- `userWithRole()` and `userWithPermissions()` — a user holding a base role, or a
  bespoke role granting exactly the permissions a test is about.
- `seedActivity()` — seeds through the real audit pipeline rather than writing an
  activity row directly.
- Fixture builders for inline references, images and PDFs, each with a comment
  explaining the non-obvious choice behind it.

Several meta-tests guard conventions rather than behaviour: audit coverage,
ability-versus-permission naming, permission-catalog completeness, translation
completeness, toast call shape and the documentation index. If one fails, it is
usually telling you about a rule rather than a bug.

## Frontend

Tailwind **v4, CSS-first** — there is no `tailwind.config.js`. Everything lives
in `resources/css/app.css`: the Flux stylesheet, the typography plugin, the dark
variant (a `.dark` class, not the media query) and the theme blocks.

Watch the `@source` directives. They cover the views, the Flux stubs in
`vendor/` and — less obviously — a model that builds class names in PHP. **A
class name generated outside a scanned file is silently purged**, so new dynamic
classes need their source registering.

Vite builds three entry points (the stylesheet, the main bundle, and passkeys).
`resources/js/` holds the editor extensions — the mention, reference and
variable suggestion plugins, the image node that links thumbnails to originals,
the hovercard engine shared by mentions and variables — plus the board's drag
and drop. A "Unable to locate file in Vite manifest" error just means the assets
have not been built.

## Things that will trip you up

- `composer test` includes lint and static analysis, so "the tests" fail on
  formatting.
- A Feature test without `uses(RefreshDatabase::class)` pollutes the database for
  every test after it.
- A policy ability sharing a name with a permission is silently never called.
- Membership needs both the pivot and the role.
- Tailwind classes built in PHP need an `@source` entry.
- Model event hooks must bind to the app's own model class, not a framework
  parent, or they never fire.
- Export having no format interface, and the browser-test reaper's strange
  command, are both deliberate — each has a comment or an ADR saying why.
