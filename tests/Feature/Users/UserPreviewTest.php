<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->viewer = User::factory()->create();
    $this->target = User::factory()->create(['name' => 'Ada Lovelace']);

    $this->project = Project::factory()->create(['short_name' => 'SHR']);
    joinProject($this->project, $this->viewer);
    joinProject($this->project, $this->target, 'admin');
});

it('serves a user preview to someone who shares a project', function () {
    $this->actingAs($this->viewer)
        ->getJson(route('users.preview', $this->target))
        ->assertOk()
        ->assertJson([
            'name' => 'Ada Lovelace',
            'avatar_url' => null,
            'initials' => 'AL',
            'roles' => [],
        ]);
});

it('includes the role in the project the mention lives in', function () {
    $this->actingAs($this->viewer)
        ->getJson(route('users.preview', $this->target).'?project=SHR')
        ->assertOk()
        ->assertJsonPath('roles', ['Admin']);
});

it('omits roles when the named project is not visible to the viewer', function () {
    // The target also belongs to a project the viewer cannot see; asking for the
    // role there must not leak it, even though the viewer can see the user.
    $private = Project::factory()->create(['short_name' => 'PRV']);
    joinProject($private, $this->target, 'admin');

    $this->actingAs($this->viewer)
        ->getJson(route('users.preview', $this->target).'?project=PRV')
        ->assertOk()
        ->assertJsonPath('roles', []);
});

it('lets a user preview themselves', function () {
    $this->actingAs($this->target)
        ->getJson(route('users.preview', $this->target))
        ->assertOk()
        ->assertJsonPath('name', 'Ada Lovelace');
});

it('forbids a user preview to someone who shares no project', function () {
    $this->actingAs(User::factory()->create())
        ->getJson(route('users.preview', $this->target))
        ->assertForbidden();
});

it('returns 404 for an unknown user', function () {
    $this->actingAs($this->viewer)
        ->getJson(route('users.preview', 'nonexistent-public-id'))
        ->assertNotFound();
});
