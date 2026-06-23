<?php

use App\Enums\CancelReason;
use App\Enums\Status;
use App\Mcp\Servers\KanvigoServer;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

function actingMember(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);

    return $user;
}

it('cancels a task and its open subtree with a reason and message via MCP', function () {
    $user = actingMember();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->status(Status::ToDo)->create();
    $child = Task::factory()->for($project)->childOf($task)->status(Status::ToDo)->create();

    KanvigoServer::tool(UpdateTaskTool::class, [
        'reference' => $task->reference,
        'cancel_reason' => CancelReason::Duplicate->name,
        'cancel_message' => 'Same as ABC-9',
    ])
        ->assertOk()
        ->assertSee(Status::Canceled->value)
        ->assertSee('Duplicate');

    $fresh = $task->fresh();

    expect($fresh->status)->toBe(Status::Canceled)
        ->and($fresh->cancel_reason)->toBe(CancelReason::Duplicate)
        ->and($fresh->cancel_message)->toBe('Same as ABC-9')
        ->and($child->fresh()->isCanceled())->toBeTrue();

    assertDatabaseHas('activities', [
        'subject_id' => $task->id,
        'action' => 'canceled',
    ]);
});

it('reopens a canceled task via MCP', function () {
    $user = actingMember();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->canceled(CancelReason::WontFix, 'old')->create();

    KanvigoServer::tool(UpdateTaskTool::class, [
        'reference' => $task->reference,
        'reopen' => true,
    ])
        ->assertOk()
        ->assertSee(Status::Planned->value);

    $fresh = $task->fresh();

    expect($fresh->status)->toBe(Status::Planned)
        ->and($fresh->isCanceled())->toBeFalse()
        ->and($fresh->cancel_reason)->toBeNull();

    assertDatabaseHas('activities', [
        'subject_id' => $task->id,
        'action' => 'reopened',
    ]);
});

it('rejects setting the Canceled status directly, steering to cancel_reason', function () {
    $user = actingMember();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->status(Status::ToDo)->create();

    KanvigoServer::tool(UpdateTaskTool::class, [
        'reference' => $task->reference,
        'status' => Status::Canceled->value,
    ])->assertHasErrors();

    expect($task->fresh()->status)->toBe(Status::ToDo);
});

it('rejects combining cancel_reason with a status change', function () {
    $user = actingMember();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->status(Status::ToDo)->create();

    KanvigoServer::tool(UpdateTaskTool::class, [
        'reference' => $task->reference,
        'cancel_reason' => CancelReason::WontFix->name,
        'status' => Status::Done->value,
    ])->assertHasErrors();
});

it('rejects changing the status of a canceled task without reopening', function () {
    $user = actingMember();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->canceled(CancelReason::Deprecated)->create();

    KanvigoServer::tool(UpdateTaskTool::class, [
        'reference' => $task->reference,
        'status' => Status::ToDo->value,
    ])->assertHasErrors();

    expect($task->fresh()->status)->toBe(Status::Canceled);
});

it('denies cancelling with a read-only token', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->status(Status::ToDo)->create();

    KanvigoServer::tool(UpdateTaskTool::class, [
        'reference' => $task->reference,
        'cancel_reason' => CancelReason::WontFix->name,
    ])->assertHasErrors();

    expect($task->fresh()->isCanceled())->toBeFalse();
});

it('exposes the cancellation reason and message through get-task', function () {
    $user = actingMember();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->canceled(CancelReason::Duplicate, 'Superseded')->create();

    KanvigoServer::tool(GetTaskTool::class, ['reference' => $task->reference])
        ->assertOk()
        ->assertStructuredContent(function ($json) {
            $json->where('cancel_reason', 'Duplicate')
                ->where('cancel_message', 'Superseded')
                ->etc();
        });
});

it('exposes the cancellation reason through list-tasks', function () {
    $user = actingMember();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    Task::factory()->for($project)->canceled(CancelReason::Deprecated)->create();

    KanvigoServer::tool(ListTasksTool::class, ['reference' => 'ABC'])
        ->assertOk()
        ->assertStructuredContent(function ($json) {
            $json->where('tasks.0.cancel_reason', 'Deprecated')->etc();
        });
});
