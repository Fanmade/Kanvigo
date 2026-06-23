<?php

use App\Enums\ProjectRole;
use App\Livewire\Projects\ProjectShow;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * An owner, a plain member, and their shared project.
 *
 * @return array{0: User, 1: User, 2: Project}
 */
function ownerMemberProject(): array
{
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    $project->members()->attach($owner, ['role' => ProjectRole::Owner->value]);
    $project->members()->attach($member, ['role' => ProjectRole::Member->value]);

    return [$owner, $member, $project];
}

function showAs(User $user, Project $project): Testable
{
    return Livewire::actingAs($user)->test(ProjectShow::class, ['short_name' => $project->short_name]);
}

it('lets the owner promote a member to admin and demote them back', function () {
    [$owner, $member, $project] = ownerMemberProject();

    showAs($owner, $project)
        ->call('setMemberRole', $member->id, ProjectRole::Admin->value)
        ->assertHasNoErrors();
    expect($project->roleFor($member))->toBe(ProjectRole::Admin);

    showAs($owner, $project)->call('setMemberRole', $member->id, ProjectRole::Member->value);
    expect($project->roleFor($member))->toBe(ProjectRole::Member);
});

it('forbids an admin or member from changing roles', function () {
    [$owner, $member, $project] = ownerMemberProject();
    $admin = User::factory()->create();
    $project->members()->attach($admin, ['role' => ProjectRole::Admin->value]);

    showAs($admin, $project)
        ->call('setMemberRole', $member->id, ProjectRole::Admin->value)
        ->assertForbidden();

    showAs($member, $project)
        ->call('setMemberRole', $admin->id, ProjectRole::Member->value)
        ->assertForbidden();

    expect($project->roleFor($member))->toBe(ProjectRole::Member)
        ->and($project->roleFor($admin))->toBe(ProjectRole::Admin);
});

it('does not let the owner change their own role', function () {
    [$owner, , $project] = ownerMemberProject();

    showAs($owner, $project)->call('setMemberRole', $owner->id, ProjectRole::Member->value);

    expect($project->roleFor($owner))->toBe(ProjectRole::Owner);
});

it('does not let ownership be handed out through the role control', function () {
    [$owner, $member, $project] = ownerMemberProject();

    showAs($owner, $project)
        ->call('setMemberRole', $member->id, ProjectRole::Owner->value)
        ->assertHasErrors('role');

    expect($project->roleFor($member))->toBe(ProjectRole::Member);
});

it('shows the manage-members control only to the owner', function () {
    [$owner, $member, $project] = ownerMemberProject();
    $admin = User::factory()->create();
    $project->members()->attach($admin, ['role' => ProjectRole::Admin->value]);

    showAs($owner, $project)->assertSee('manage-members', false);
    showAs($admin, $project)->assertDontSee('manage-members', false);
    showAs($member, $project)->assertDontSee('manage-members', false);
});
