<?php

use App\Mcp\Servers\KanvigoServer;
use App\Mcp\Tools\SetAssigneesTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->withMembers([$this->user])->create(['short_name' => 'ABC']);
    $this->task = Task::factory()->for($this->project)->create();
});

it('sets a task assignees to project members by their public id', function () {
    Sanctum::actingAs($this->user, ['read', 'write']);
    $member = User::factory()->create(['name' => 'Dana']);
    joinProject($this->project, $member);
    $outsider = User::factory()->create();

    KanvigoServer::tool(SetAssigneesTool::class, [
        'reference' => $this->task->reference,
        'assignee_ids' => [$member->public_id, $outsider->public_id],
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('reference', $this->task->reference)
            ->where('assignees', [['id' => $member->public_id, 'name' => 'Dana']])
            ->etc());

    expect($this->task->fresh()->assignees()->pluck('users.id')->all())->toBe([$member->id]);
    // Assigning subscribes the new assignee to the task.
    expect($this->task->subscribers()->whereKey($member->id)->exists())->toBeTrue();
});

it('clears assignees with an empty set', function () {
    Sanctum::actingAs($this->user, ['read', 'write']);
    $this->task->assignees()->attach($this->user);

    KanvigoServer::tool(SetAssigneesTool::class, [
        'reference' => $this->task->reference,
        'assignee_ids' => [],
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('assignees', [])->etc());

    expect($this->task->fresh()->assignees()->count())->toBe(0);
});

it('denies setting assignees with a read-only token', function () {
    Sanctum::actingAs($this->user, ['read']);
    $member = User::factory()->create();
    joinProject($this->project, $member);

    KanvigoServer::tool(SetAssigneesTool::class, [
        'reference' => $this->task->reference,
        'assignee_ids' => [$member->public_id],
    ])->assertHasErrors();
});

it('errors on a task the user cannot edit', function () {
    Sanctum::actingAs($this->user, ['read', 'write']);
    $foreign = Task::factory()->for(Project::factory()->create(['short_name' => 'XYZ']))->create();

    KanvigoServer::tool(SetAssigneesTool::class, [
        'reference' => $foreign->reference,
        'assignee_ids' => [],
    ])->assertHasErrors();
});
