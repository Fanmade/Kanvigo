---
name: eloquent-query-classes
description: Extract reused or complex Eloquent queries into single-purpose query classes under app/Queries. Activate when a non-trivial query (multiple wheres/joins/scopes, eager loads, aggregates, or a conditional builder) is duplicated across controllers, Livewire components, MCP tools, commands, jobs or exports — or when asked to centralize, name, or test a business query. Mirrors the project's Action class style (a single handle() method). Use it to decide whether a query earns extraction and how to name, place, return from and test it. Do not use for one-off trivial lookups or generic CRUD repositories.
metadata:
  author: kanbrio
  source: https://wendelladriel.com/blog/eloquent-query-classes-pattern
---

# Eloquent Query Classes

A query class names and centralizes one meaningful database query so it lives in
exactly one place instead of being copy-pasted across the board, the task page, an
MCP tool and a command. It is the read-side sibling of an Action: same single
`handle()` entry point, same "single source of truth" intent as
`app/Actions/CreateTask.php`.

This is **not** a repository. A repository abstracts a whole model behind generic
CRUD. A query class answers one specific business question — "tasks ready to start",
"overdue tasks for this project" — and nothing else.

## When to extract (and when not to)

Start with plain Eloquent in the caller. Extract only when the query **earns it**:

- **Reuse** — the same non-trivial query appears (or is about to) in two or more
  callers: a Livewire component *and* an MCP tool, a controller *and* a command.
- **Complexity** — enough wheres, joins, scopes, eager loads or aggregates that the
  intent is no longer obvious at the call site.
- **Business meaning** — the query encodes a rule ("blocked means a blocker isn't
  Done yet") that you want defined once and tested directly.

Do **not** extract a one-line `Task::find($id)` or a trivial `where`. Inlining is
correct far more often than not. Prefer a model **scope** when the fragment is a
reusable *predicate* you compose into other queries; reach for a query class when
it's a complete, named question a caller asks. The two compose — a query class can
call scopes.

## Structure

One class, one public `handle()` method. Match this project's Action conventions
(see `app/Actions/CreateTask.php`): plain `class`, no `strict_types` preamble, a
PHPDoc block describing the business intent, explicit param and return types, named
arguments at the call site.

```php
<?php

namespace App\Queries;

use App\Enums\Status;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;

/**
 * Top-level tasks in a project that are ready to be picked up: Planned or ToDo
 * and not canceled. Shared by the board lanes, the "what's next" MCP tool and
 * the daily digest command.
 */
class ReadyToStartTasksQuery
{
    /**
     * @return Collection<int, \App\Models\Task>
     */
    public function handle(Project $project): Collection
    {
        return $project->tasks()
            ->whereNull('parent_id')
            ->whereNull('canceled_at')
            ->whereIn('status', [Status::Planned, Status::ToDo])
            ->with('assignees')
            ->orderBy('priority')
            ->get();
    }
}
```

Call it with named arguments so the intent reads clearly:

```php
$tasks = (new ReadyToStartTasksQuery)->handle(project: $project);
```

> The blog uses `final readonly class`. This codebase's Actions are plain `class`
> (see `app/Actions`), so match that for consistency. Consistency with siblings beats
> the blog's exact modifiers — follow whatever `app/Actions` and any existing
> `app/Queries` already do.

## Return a result or a builder — pick deliberately

**Return a concrete result** when there's one obvious way to consume it — a
`Collection`, a paginator, a count, a bool. Execute inside `handle()`.

```php
/** Count of open (non-canceled, non-Done) tasks assigned to a user. */
class OpenTaskCountQuery
{
    public function handle(User $user): int
    {
        return $user->assignedTasks()
            ->whereNull('canceled_at')
            ->whereNot('status', Status::Done)
            ->count();
    }
}
```

**Return a `Builder`** when callers genuinely need to keep composing — one paginates,
another exports, another counts. Don't force this; an unexecuted builder is only
worth it when the composition is real.

```php
use Illuminate\Database\Eloquent\Builder;

/** @return Builder<\App\Models\Task> Overdue, still-open tasks — caller decides how to consume. */
class OverdueTasksQuery
{
    public function handle(Project $project): Builder
    {
        return $project->tasks()
            ->whereNull('canceled_at')
            ->whereNot('status', Status::Done)
            ->whereDate('due_date', '<', now())
            ->latest('due_date');
    }
}
```

## Conditional filters

Use `when()` for optional arguments rather than branching with `if`:

```php
public function handle(Project $project, ?Status $status = null): Collection
{
    return $project->tasks()
        ->whereNull('canceled_at')
        ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
        ->get();
}
```

## Write queries are fine

A bulk conditional write is a legitimate query class — the condition *is* the
business rule. Return the affected-row count.

```php
/** Cancel every still-Planned task whose project was archived before $cutoff. */
class CancelStaleTasksQuery
{
    public function handle(CarbonImmutable $cutoff): int
    {
        return Task::query()
            ->where('status', Status::Planned)
            ->whereNull('canceled_at')
            ->whereHas('project', fn ($query) => $query->where('archived_at', '<', $cutoff))
            ->update(['canceled_at' => now(), 'cancel_reason' => CancelReason::Deprecated]);
    }
}
```

## Placement & naming

- Live in `app/Queries/` (namespace `App\Queries`). Create the directory on first use.
- Name after the **business question**, suffixed `Query`: `ReadyToStartTasksQuery`,
  `OverdueTasksQuery` — never `GetTasksQuery` or `TaskQuery`.
- One public `handle()` per class. No generic base class until real duplication
  across several query classes proves one is needed.

## Testing

Test the **business rule**, not Eloquent. Seed data that should and shouldn't match
via factories, run `handle()`, assert the boundary. (Pest is this project's runner —
see the `pest-testing` skill.)

```php
it('excludes canceled and subtask tasks from ready-to-start', function () {
    $project = Project::factory()->create();
    $ready = Task::factory()->for($project)->create(['status' => Status::ToDo]);
    Task::factory()->for($project)->create(['status' => Status::Planned, 'canceled_at' => now()]);
    Task::factory()->for($project)->childOf($ready)->create(['status' => Status::Planned]);

    $result = (new ReadyToStartTasksQuery)->handle(project: $project);

    expect($result)->toHaveCount(1)
        ->and($result->first()->is($ready))->toBeTrue();
});
```

## Checklist

- [ ] Query is reused or complex enough to earn extraction (not a trivial lookup).
- [ ] In `app/Queries/`, named after the business question, suffix `Query`.
- [ ] Single `handle()`, typed params/return, PHPDoc intent — matches `app/Actions` style.
- [ ] Returns a concrete result, or a `Builder` only when composition is real.
- [ ] All previous call sites now delegate to the class (no leftover duplication).
- [ ] A test asserts the business rule via factories.
