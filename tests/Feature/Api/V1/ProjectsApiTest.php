<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('requires authentication', function () {
    $this->getJson('/api/v1/projects')->assertUnauthorized();
});

it('returns the current token user', function () {
    $user = User::factory()->create(['name' => 'Dana']);
    Sanctum::actingAs($user, ['read']);

    $this->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.id', $user->public_id)
        ->assertJsonPath('data.name', 'Dana')
        ->assertJsonPath('data.email', $user->email);
});

it('lists only the projects the user is a member of', function () {
    $user = User::factory()->create();
    $mine = Project::factory()->create(['short_name' => 'AAA', 'title' => 'Mine']);
    joinProject($mine, $user);
    Project::factory()->create(['short_name' => 'BBB', 'title' => 'Theirs']);

    Sanctum::actingAs($user, ['read']);

    $this->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.short_name', 'AAA')
        ->assertJsonStructure(['data' => [['short_name', 'title', 'description', 'task_count']], 'links', 'meta']);
});

it('includes the top-level task count', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user);
    Task::factory()->for($project)->count(2)->create();

    Sanctum::actingAs($user, ['read']);

    $this->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonPath('data.0.task_count', 2);
});

it('shows a single project by short name', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC', 'title' => 'Apollo']);
    joinProject($project, $user);

    Sanctum::actingAs($user, ['read']);

    $this->getJson('/api/v1/projects/ABC')
        ->assertOk()
        ->assertJsonPath('data.short_name', 'ABC')
        ->assertJsonPath('data.title', 'Apollo');
});

it('404s a project the user cannot access, without leaking it', function () {
    $user = User::factory()->create();
    Project::factory()->create(['short_name' => 'XYZ']);

    Sanctum::actingAs($user, ['read']);

    $this->getJson('/api/v1/projects/XYZ')->assertNotFound();
});
