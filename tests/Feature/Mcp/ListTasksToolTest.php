<?php

use App\Enums\Status;
use App\Mcp\Servers\KanvigoServer;
use App\Mcp\Tools\ListTasksTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists the tasks of a project the user can access', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();

    KanvigoServer::actingAs($user)->tool(ListTasksTool::class, ['reference' => $project->short_name])
        ->assertOk()
        ->assertSee($task->reference);
});

it('filters tasks by status', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $done = Task::factory()->for($project)->status(Status::Done)->create();
    $planned = Task::factory()->for($project)->status(Status::Planned)->create();

    KanvigoServer::actingAs($user)->tool(ListTasksTool::class, [
        'reference' => $project->short_name,
        'status' => Status::Done->value,
    ])
        ->assertOk()
        ->assertSee($done->reference)
        ->assertDontSee($planned->reference);
});

it('denies listing tasks of a project the user cannot access', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    Task::factory()->for($project)->create();

    KanvigoServer::actingAs($user)->tool(ListTasksTool::class, ['reference' => $project->short_name])
        ->assertHasErrors();
});

it('restricts the list to a parent task\'s direct subtasks', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $root = Task::factory()->for($project)->create();
    $child = Task::factory()->for($project)->childOf($root)->create();
    $grandchild = Task::factory()->for($project)->childOf($child)->create();

    KanvigoServer::actingAs($user)->tool(ListTasksTool::class, [
        'reference' => $project->short_name,
        'parent' => $root->reference,
    ])
        ->assertOk()
        ->assertSee($child->reference)        // a direct subtask
        ->assertDontSee($grandchild->reference) // a grandchild, not direct
        ->assertDontSee('"reference":"'.$root->reference.'"'); // the root itself is excluded
});

it('reports each task\'s parent so nesting can be reconstructed', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $root = Task::factory()->for($project)->create();
    $child = Task::factory()->for($project)->childOf($root)->create();

    KanvigoServer::actingAs($user)->tool(ListTasksTool::class, ['reference' => $project->short_name])
        ->assertOk()
        ->assertStructuredContent(function ($json) use ($root, $child) {
            $tasks = collect($json->toArray()['tasks']);
            expect($tasks->firstWhere('reference', $root->reference)['parent'])->toBeNull()
                ->and($tasks->firstWhere('reference', $child->reference)['parent'])->toBe($root->reference);
            $json->etc();
        });
});

it('includes each task\'s tags', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();
    $task->syncTags('design');

    KanvigoServer::actingAs($user)->tool(ListTasksTool::class, ['reference' => $project->short_name])
        ->assertOk()
        ->assertSee('design');
});
