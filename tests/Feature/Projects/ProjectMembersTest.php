<?php

use App\Authorization\ProjectRoleProvisioner;
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
    $project = Project::factory()
        ->withOwner($owner)
        ->withMember($member)
        ->create(['short_name' => 'ABC']);

    return [$owner, $member, $project];
}

/**
 * Add a user to the project with the given package role.
 */
function memberWithRole(Project $project, string $role): User
{
    $user = User::factory()->create();
    $project->members()->attach($user);
    app(ProjectRoleProvisioner::class)->syncMember($project, $user, $role);

    return $user;
}

function showAs(User $user, Project $project): Testable
{
    return Livewire::actingAs($user)->test(ProjectShow::class, ['short_name' => $project->short_name]);
}

it('lets the owner add a second role to a member and remove it again', function () {
    [$owner, $member, $project] = ownerMemberProject();

    showAs($owner, $project)
        ->call('addMemberRole', $member->id, 'admin')
        ->assertHasNoErrors();
    expect($project->roleNamesFor($member))->toBe(['admin', 'member']);

    showAs($owner, $project)->call('removeMemberRole', $member->id, 'admin');
    expect($project->roleNamesFor($member))->toBe(['member']);
});

it('forbids an admin or member from changing roles', function () {
    [$owner, $member, $project] = ownerMemberProject();
    $admin = memberWithRole($project, 'admin');

    showAs($admin, $project)
        ->call('addMemberRole', $member->id, 'admin')
        ->assertForbidden();

    showAs($member, $project)
        ->call('removeMemberRole', $admin->id, 'admin')
        ->assertForbidden();

    expect($project->roleNameFor($member))->toBe('member')
        ->and($project->roleNameFor($admin))->toBe('admin');
});

it('does not let the owner change their own roles', function () {
    [$owner, , $project] = ownerMemberProject();

    showAs($owner, $project)->call('addMemberRole', $owner->id, 'member');

    expect($project->roleNamesFor($owner))->toBe(['owner']);
});

it('does not let ownership be handed out through the role control', function () {
    [$owner, $member, $project] = ownerMemberProject();

    showAs($owner, $project)
        ->call('addMemberRole', $member->id, 'owner')
        ->assertHasErrors('role');

    expect($project->roleNameFor($member))->toBe('member');
});

it('lets the owner add an existing user to the project as a member', function () {
    [$owner, , $project] = ownerMemberProject();
    $newcomer = User::factory()->create(['name' => 'Dana New']);

    showAs($owner, $project)->call('addMember', $newcomer->id);

    expect($project->roleNameFor($newcomer))->toBe('member');
});

it('forbids a non-owner from adding members', function () {
    [, $member, $project] = ownerMemberProject();
    $newcomer = User::factory()->create();

    showAs($member, $project)
        ->call('addMember', $newcomer->id)
        ->assertForbidden();

    expect($project->members()->whereKey($newcomer->id)->exists())->toBeFalse();
});

it('lets the owner remove a member but not themselves', function () {
    [$owner, $member, $project] = ownerMemberProject();

    showAs($owner, $project)->call('removeMember', $member->id);
    expect($project->members()->whereKey($member->id)->exists())->toBeFalse();

    showAs($owner, $project)->call('removeMember', $owner->id);
    expect($project->isOwner($owner))->toBeTrue();
});

it('offers only matching non-members in the add picker', function () {
    [$owner, $member, $project] = ownerMemberProject();
    User::factory()->create(['name' => 'Alice Example']);
    User::factory()->create(['name' => 'Bob Other']);

    $names = showAs($owner, $project)
        ->set('memberQuery', 'Alice')
        ->instance()->addableUsers()->pluck('name')->all();

    expect($names)->toContain('Alice Example')
        ->and($names)->not->toContain('Bob Other')
        ->and($names)->not->toContain($member->name);
});

it('shows the manage-members control only to the owner', function () {
    [$owner, $member, $project] = ownerMemberProject();
    $admin = memberWithRole($project, 'admin');

    showAs($owner, $project)->assertSee('manage-members', false);
    showAs($admin, $project)->assertDontSee('manage-members', false);
    showAs($member, $project)->assertDontSee('manage-members', false);
});
