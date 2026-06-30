<?php

use App\Enums\Permission;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('requires authentication', function () {
    $target = User::factory()->create();

    $this->getJson("/api/v1/users/{$target->public_id}")->assertUnauthorized();
});

it('resolves a user by their public id and includes email for a shared-project member', function () {
    $project = Project::factory()->create();
    $viewer = User::factory()->create();
    $target = User::factory()->create(['name' => 'Dana', 'email' => 'dana@example.com']);
    joinProject($project, [$viewer, $target]);

    Sanctum::actingAs($viewer, ['read']);

    $this->getJson("/api/v1/users/{$target->public_id}")
        ->assertOk()
        ->assertJsonPath('data.id', $target->public_id)
        ->assertJsonPath('data.name', 'Dana')
        ->assertJsonPath('data.email', 'dana@example.com');
});

it('lets a user resolve their own contact details', function () {
    $viewer = User::factory()->create(['email' => 'me@example.com']);
    Sanctum::actingAs($viewer, ['read']);

    $this->getJson("/api/v1/users/{$viewer->public_id}")
        ->assertOk()
        ->assertJsonPath('data.email', 'me@example.com');
});

it('shows the name but withholds email from an access-all viewer who shares no project', function () {
    $viewer = User::factory()->create();
    $viewer->syncPermissions([Permission::AccessAllProjects]);
    $target = User::factory()->create(['name' => 'Dana', 'email' => 'dana@example.com']);

    Sanctum::actingAs($viewer, ['read']);

    $response = $this->getJson("/api/v1/users/{$target->public_id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Dana');

    expect($response->json('data'))->not->toHaveKey('email');
});

it('includes email for a user administrator who shares no project', function () {
    $viewer = User::factory()->canManageUsers()->create();
    $target = User::factory()->create(['email' => 'dana@example.com']);

    Sanctum::actingAs($viewer, ['read']);

    $this->getJson("/api/v1/users/{$target->public_id}")
        ->assertOk()
        ->assertJsonPath('data.email', 'dana@example.com');
});

it('404s a user the viewer shares no project with, without leaking them', function () {
    $viewer = User::factory()->create();
    $target = User::factory()->create();

    Sanctum::actingAs($viewer, ['read']);

    $this->getJson("/api/v1/users/{$target->public_id}")->assertNotFound();
});
