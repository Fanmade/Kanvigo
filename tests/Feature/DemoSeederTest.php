<?php

use App\Enums\Status;
use App\Models\Comment;
use App\Models\Note;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Membership alone grants nothing since the delegated-permissions adoption —
 * a seeded local environment must come with working project roles, or every
 * action (even viewing the demo project) 403s out of the box (KAN-406).
 */
it('seeds demo members with working project roles', function () {
    $this->seed(DemoSeeder::class);

    $project = Project::query()->where('short_name', 'PER')->firstOrFail();
    $admin = User::query()->where('email', config('admin.email') ?: 'admin@example.com')->firstOrFail();
    $member = $project->members()->where('users.id', '!=', $admin->id)->firstOrFail();
    $task = $project->tasks()->firstOrFail();

    expect($admin->can('view-project', $project))->toBeTrue()
        ->and($admin->can('update', $task))->toBeTrue()
        ->and($member->can('view-project', $project))->toBeTrue()
        ->and($member->can('view', $task))->toBeTrue();
});

/**
 * The point of the demo data is that a fresh install looks like a real board
 * (KAN-47): typed, tagged, assigned cards spread across the statuses, in more
 * than one project — not a wall of identical placeholder rows.
 */
it('seeds two projects whose tasks carry types, tags, assignees and priorities', function () {
    $this->seed(DemoSeeder::class);

    $project = Project::query()->where('short_name', 'PER')->firstOrFail();

    expect(Project::query()->pluck('short_name')->all())->toEqualCanonicalizing(['PER', 'WEB'])
        ->and($project->taskTypes()->count())->toBe(count(TaskType::DEFAULTS))
        ->and($project->tags()->count())->toBeGreaterThan(0)
        ->and($project->tasks()->whereNotNull('task_type_id')->count())->toBeGreaterThan(0)
        ->and($project->tasks()->whereNotNull('parent_id')->count())->toBeGreaterThan(0)
        ->and($project->tasks()->whereNotNull('due_date')->count())->toBeGreaterThan(0);

    // Every working column has cards, so the board is not lopsided on first run.
    foreach (Status::columns() as $status) {
        expect($project->tasks()->where('status', $status)->count())->toBeGreaterThan(0);
    }

    $tagged = $project->tasks()->has('tags')->count();
    $assigned = $project->tasks()->has('assignees')->count();

    expect($tagged)->toBeGreaterThan(0)->and($assigned)->toBeGreaterThan(0);
});

/**
 * Cards fall back to the id when they share a position, so seeded-in-order data
 * renders every lane as a tidy ascending list. The seeder hands out a shuffled
 * position per column instead, the way a team that arranges its board would.
 */
it('gives every seeded card a distinct board position within its column', function () {
    $this->seed(DemoSeeder::class);

    foreach (Status::columns() as $status) {
        $positions = Task::query()->where('status', $status)->pluck('position');

        expect($positions)->not->toBeEmpty()
            ->and($positions->unique())->toHaveCount($positions->count())
            ->and($positions->min())->toBeGreaterThan(0);
    }
});

/**
 * The states that are easy to forget when demoing — a blocked card, a canceled
 * task and an archived one — are seeded on purpose.
 */
it('seeds a blocked, a canceled and an archived task', function () {
    $this->seed(DemoSeeder::class);

    $project = Project::query()->where('short_name', 'PER')->firstOrFail();

    $blocked = $project->tasks()->get()->first(static fn (Task $task): bool => $task->isBlocked());

    expect($blocked)->not->toBeNull()
        ->and($project->tasks()->whereNotNull('canceled_at')->count())->toBe(1)
        ->and($project->tasks()->whereNotNull('archived_at')->count())->toBe(1);
});

/**
 * Comments and notes ship with the demo too, so the discussion thread and the
 * notes page are not empty on a fresh install.
 */
it('seeds a comment thread with a reply, and both a private and a shared note', function () {
    $this->seed(DemoSeeder::class);

    expect(Comment::query()->count())->toBeGreaterThan(1)
        ->and(Comment::query()->whereNotNull('parent_id')->count())->toBeGreaterThan(0)
        ->and(Note::query()->where('is_public', true)->count())->toBe(1)
        ->and(Note::query()->where('is_public', false)->count())->toBe(1);
});

/**
 * The seeded doc bodies carry hand-written reference markup, so this also guards
 * that markup staying in step with the parser: if the two drift, the demo data
 * silently stops producing backlinks.
 */
it('seeds a doc tree whose inline reference links a demo task', function () {
    $this->seed(DemoSeeder::class);

    $project = Project::query()->where('short_name', 'PER')->firstOrFail();

    $handbook = $project->docs()->where('title', 'Team handbook')->firstOrFail();
    $definition = $project->docs()->where('title', 'Definition of done')->firstOrFail();

    expect($handbook->is_public)->toBeTrue()
        ->and($handbook->children)->toHaveCount(2)
        ->and($definition->parent_id)->toBe($handbook->id)
        ->and($project->docs()->where('is_public', false)->count())->toBe(1);

    $referenced = $definition->references()->first();

    expect($referenced)->not->toBeNull()
        ->and($referenced->referencedBy()->first()?->is($definition))->toBeTrue();
});
