<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A project plus one member of each role and an outside non-member.
 *
 * @return array{project: Project, owner: User, admin: User, member: User, outsider: User}
 */
function projectWithEveryRole(): array
{
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();

    $project = Project::factory()
        ->withOwner($owner)
        ->withMember($admin, 'admin')
        ->withMember($member)
        ->create();

    return [
        'project' => $project,
        'owner' => $owner,
        'admin' => $admin,
        'member' => $member,
        'outsider' => User::factory()->create(),
    ];
}

it('lets any member — but no outsider — view and contribute', function () {
    ['project' => $project, 'owner' => $owner, 'admin' => $admin, 'member' => $member, 'outsider' => $outsider] = projectWithEveryRole();

    foreach ([$owner, $admin, $member] as $insider) {
        expect($insider->can('view', $project))->toBeTrue()
            ->and($insider->can('update', $project))->toBeTrue();
    }

    expect($outsider->can('view', $project))->toBeFalse()
        ->and($outsider->can('update', $project))->toBeFalse();
});

it('restricts editing settings and deleting the project to admins and the owner', function () {
    ['project' => $project, 'owner' => $owner, 'admin' => $admin, 'member' => $member, 'outsider' => $outsider] = projectWithEveryRole();

    expect($owner->can('manageSettings', $project))->toBeTrue()
        ->and($admin->can('manageSettings', $project))->toBeTrue()
        ->and($member->can('manageSettings', $project))->toBeFalse()
        ->and($outsider->can('manageSettings', $project))->toBeFalse()
        ->and($owner->can('delete', $project))->toBeTrue()
        ->and($admin->can('delete', $project))->toBeTrue()
        ->and($member->can('delete', $project))->toBeFalse();
});

it('restricts managing members to the owner', function () {
    ['project' => $project, 'owner' => $owner, 'admin' => $admin, 'member' => $member] = projectWithEveryRole();

    expect($owner->can('manageMembers', $project))->toBeTrue()
        ->and($admin->can('manageMembers', $project))->toBeFalse()
        ->and($member->can('manageMembers', $project))->toBeFalse();
});
