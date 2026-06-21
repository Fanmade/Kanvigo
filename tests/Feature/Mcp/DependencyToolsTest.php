<?php

use App\Enums\Status;
use App\Mcp\Servers\KanbrioServer;
use App\Mcp\Tools\AddDependencyTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\RemoveDependencyTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->withMembers([$this->user])->create(['short_name' => 'ABC']);
});

// --- Read: get tools -------------------------------------------------------

it('exposes a task\'s blockers, blocked items and blocked flag in the get tool', function () {
    $task = Task::factory()->for($this->project)->create();
    $blocker = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $task->addBlocker($blocker);

    KanbrioServer::actingAs($this->user)->tool(GetTaskTool::class, ['reference' => $task->reference])
        ->assertOk()
        ->assertSee($blocker->reference)
        ->assertSee('"is_blocked":true');

    // The blocker reports the task it blocks, and is not itself blocked.
    KanbrioServer::actingAs($this->user)->tool(GetTaskTool::class, ['reference' => $blocker->reference])
        ->assertOk()
        ->assertSee($task->reference)
        ->assertSee('"is_blocked":false');
});

it('reports a task as unblocked once its blocker is complete', function () {
    $task = Task::factory()->for($this->project)->create();
    $blocker = Task::factory()->for($this->project)->status(Status::Done)->create();
    $task->addBlocker($blocker);

    KanbrioServer::actingAs($this->user)->tool(GetTaskTool::class, ['reference' => $task->reference])
        ->assertOk()
        ->assertSee('"is_blocked":false');
});

// --- Read: list tools ------------------------------------------------------

it('surfaces the is_blocked flag in the list-tasks tool', function () {
    $task = Task::factory()->for($this->project)->create();
    $blocker = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $task->addBlocker($blocker);

    KanbrioServer::actingAs($this->user)->tool(ListTasksTool::class, ['reference' => $this->project->short_name])
        ->assertOk()
        ->assertSee('"is_blocked":true')
        ->assertSee('"is_blocked":false');
});

// --- Write: add-dependency -------------------------------------------------

it('links a blocked_by dependency and records the activity', function () {
    Sanctum::actingAs($this->user, ['read', 'write']);
    $task = Task::factory()->for($this->project)->create();
    $blocker = Task::factory()->for($this->project)->create();

    KanbrioServer::tool(AddDependencyTool::class, [
        'reference' => $task->reference,
        'related_reference' => $blocker->reference,
        'direction' => 'blocked_by',
    ])
        ->assertOk()
        ->assertSee($blocker->reference);

    assertDatabaseHas('dependencies', [
        'dependent_type' => $task->getMorphClass(),
        'dependent_id' => $task->id,
        'blocker_type' => $blocker->getMorphClass(),
        'blocker_id' => $blocker->id,
    ]);

    assertDatabaseHas('activities', [
        'action' => 'dependency_changed',
        'subject_type' => $task->getMorphClass(),
        'subject_id' => $task->id,
    ]);

    $activity = $task->activities()->where('action', 'dependency_changed')->first();
    expect(json_decode((string) $activity->new_value, true))
        ->toBe(['direction' => 'blocked_by', 'reference' => $blocker->reference]);
});

it('links a blocks dependency in the reverse direction', function () {
    Sanctum::actingAs($this->user, ['read', 'write']);
    $task = Task::factory()->for($this->project)->create();
    $blocked = Task::factory()->for($this->project)->create();

    KanbrioServer::tool(AddDependencyTool::class, [
        'reference' => $task->reference,
        'related_reference' => $blocked->reference,
        'direction' => 'blocks',
    ])->assertOk();

    assertDatabaseHas('dependencies', [
        'dependent_type' => $blocked->getMorphClass(),
        'dependent_id' => $blocked->id,
        'blocker_type' => $task->getMorphClass(),
        'blocker_id' => $task->id,
    ]);
});

it('rejects a self-dependency', function () {
    Sanctum::actingAs($this->user, ['read', 'write']);
    $task = Task::factory()->for($this->project)->create();

    KanbrioServer::tool(AddDependencyTool::class, [
        'reference' => $task->reference,
        'related_reference' => $task->reference,
        'direction' => 'blocked_by',
    ])->assertHasErrors();
});

it('rejects a dependency that would create a cycle', function () {
    Sanctum::actingAs($this->user, ['read', 'write']);
    $task = Task::factory()->for($this->project)->create();
    $blocker = Task::factory()->for($this->project)->create();
    $task->addBlocker($blocker);

    // task is already blocked by blocker; making blocker depend on task closes a cycle.
    KanbrioServer::tool(AddDependencyTool::class, [
        'reference' => $blocker->reference,
        'related_reference' => $task->reference,
        'direction' => 'blocked_by',
    ])->assertHasErrors();
});

it('returns an error for an unknown related reference', function () {
    Sanctum::actingAs($this->user, ['read', 'write']);
    $task = Task::factory()->for($this->project)->create();

    KanbrioServer::tool(AddDependencyTool::class, [
        'reference' => $task->reference,
        'related_reference' => 'ABC-99',
        'direction' => 'blocked_by',
    ])->assertHasErrors();
});

it('denies linking to an item the user cannot access', function () {
    Sanctum::actingAs($this->user, ['read', 'write']);
    $task = Task::factory()->for($this->project)->create();

    $otherProject = Project::factory()->create(['short_name' => 'XYZ']);
    $hidden = Task::factory()->for($otherProject)->create();

    KanbrioServer::tool(AddDependencyTool::class, [
        'reference' => $task->reference,
        'related_reference' => $hidden->reference,
        'direction' => 'blocked_by',
    ])->assertHasErrors();

    assertDatabaseMissing('dependencies', ['dependent_id' => $task->id]);
});

it('denies adding a dependency with a read-only token', function () {
    Sanctum::actingAs($this->user, ['read']);
    $task = Task::factory()->for($this->project)->create();
    $blocker = Task::factory()->for($this->project)->create();

    KanbrioServer::tool(AddDependencyTool::class, [
        'reference' => $task->reference,
        'related_reference' => $blocker->reference,
        'direction' => 'blocked_by',
    ])->assertHasErrors();
});

// --- Write: remove-dependency ----------------------------------------------

it('removes an existing dependency in either direction', function () {
    Sanctum::actingAs($this->user, ['read', 'write']);
    $task = Task::factory()->for($this->project)->create();
    $blocker = Task::factory()->for($this->project)->create();
    $task->addBlocker($blocker);

    KanbrioServer::tool(RemoveDependencyTool::class, [
        'reference' => $task->reference,
        'related_reference' => $blocker->reference,
    ])->assertOk();

    assertDatabaseMissing('dependencies', [
        'dependent_id' => $task->id,
        'blocker_id' => $blocker->id,
    ]);

    assertDatabaseHas('activities', [
        'action' => 'dependency_changed',
        'subject_id' => $task->id,
    ]);

    $activity = $task->activities()->where('action', 'dependency_changed')->first();
    expect(json_decode((string) $activity->old_value, true))
        ->toBe(['direction' => 'blocked_by', 'reference' => $blocker->reference]);
});

it('returns an error when removing a dependency that does not exist', function () {
    Sanctum::actingAs($this->user, ['read', 'write']);
    $task = Task::factory()->for($this->project)->create();
    $other = Task::factory()->for($this->project)->create();

    KanbrioServer::tool(RemoveDependencyTool::class, [
        'reference' => $task->reference,
        'related_reference' => $other->reference,
    ])->assertHasErrors();
});

it('denies removing a dependency with a read-only token', function () {
    Sanctum::actingAs($this->user, ['read']);
    $task = Task::factory()->for($this->project)->create();
    $blocker = Task::factory()->for($this->project)->create();
    $task->addBlocker($blocker);

    KanbrioServer::tool(RemoveDependencyTool::class, [
        'reference' => $task->reference,
        'related_reference' => $blocker->reference,
    ])->assertHasErrors();
});
