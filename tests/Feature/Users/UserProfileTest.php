<?php

use App\Enums\Permission;
use App\Livewire\Users\UserProfile;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create(['name' => 'Ada Lovelace']);
    $this->viewer = User::factory()->create(['name' => 'Grace Hopper']);

    // A project the owner and viewer share, plus a task the owner acted on.
    $this->shared = Project::factory()->withOwner($this->owner)->create(['short_name' => 'SHR', 'title' => 'Shared Work']);
    joinProject($this->shared, $this->viewer);

    $task = Task::factory()->for($this->shared)->create(['title' => 'A shared task']);
    $task->activities()->create(['user_id' => $this->owner->id, 'action' => 'created']);
});

it('shows the profile to a member who shares a project', function () {
    Livewire::actingAs($this->viewer)
        ->test(UserProfile::class, ['user' => $this->owner])
        ->assertOk()
        ->assertSeeHtml('data-test="user-profile-name"')
        ->assertSee('Ada Lovelace')
        ->assertSee('Shared Work')
        ->assertSee('SHR-1')
        ->assertSee('created this');
});

it('lets a user view their own profile', function () {
    Livewire::actingAs($this->owner)
        ->test(UserProfile::class, ['user' => $this->owner])
        ->assertOk()
        ->assertSee('Ada Lovelace');
});

it('forbids viewing a stranger who shares no project', function () {
    $stranger = User::factory()->create();

    Livewire::actingAs($stranger)
        ->test(UserProfile::class, ['user' => $this->owner])
        ->assertForbidden();
});

it('lets an access-all-projects holder view any profile', function () {
    $admin = User::factory()->create();
    $admin->syncPermissions([Permission::AccessAllProjects]);

    Livewire::actingAs($admin)
        ->test(UserProfile::class, ['user' => $this->owner])
        ->assertOk()
        ->assertSee('Ada Lovelace');
});

it('only lists projects the viewer also belongs to', function () {
    // A project the owner is in but the viewer is not.
    $private = Project::factory()->withOwner($this->owner)->create(['short_name' => 'PRV', 'title' => 'Private Work']);

    Livewire::actingAs($this->viewer)
        ->test(UserProfile::class, ['user' => $this->owner])
        ->assertSee('Shared Work')
        ->assertDontSee('Private Work');
});

it('hides activity from projects the viewer cannot see', function () {
    $private = Project::factory()->withOwner($this->owner)->create(['short_name' => 'PRV']);
    $secretTask = Task::factory()->for($private)->create(['title' => 'Secret task']);
    $secretTask->activities()->create(['user_id' => $this->owner->id, 'action' => 'created']);

    Livewire::actingAs($this->viewer)
        ->test(UserProfile::class, ['user' => $this->owner])
        ->assertSee('SHR-1')
        ->assertDontSee('PRV-1');
});
