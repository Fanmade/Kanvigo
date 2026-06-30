<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A project plus one member of each role and an outside non-member.
 *
 * @return array{project: Project, owner: User, admin: User, member: User, viewer: User, outsider: User}
 */
function projectWithEveryRole(): array
{
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $viewer = User::factory()->create();

    $project = Project::factory()
        ->withOwner($owner)
        ->withMember($admin, 'admin')
        ->withMember($member)
        ->withMember($viewer, 'viewer')
        ->create();

    return [
        'project' => $project,
        'owner' => $owner,
        'admin' => $admin,
        'member' => $member,
        'viewer' => $viewer,
        'outsider' => User::factory()->create(),
    ];
}

it('lets any contributing member — but no outsider — view and create tasks', function () {
    ['project' => $project, 'owner' => $owner, 'admin' => $admin, 'member' => $member, 'outsider' => $outsider] = projectWithEveryRole();

    foreach ([$owner, $admin, $member] as $insider) {
        expect($insider->can('view', $project))->toBeTrue()
            ->and($insider->can('create-task', $project))->toBeTrue();
    }

    expect($outsider->can('view', $project))->toBeFalse()
        ->and($outsider->can('create-task', $project))->toBeFalse();
});

it('lets a read-only viewer view but not contribute', function () {
    ['project' => $project, 'viewer' => $viewer] = projectWithEveryRole();

    // A viewer holds view-project only; the contribute permissions that gate the
    // project page's affordances must all be denied (KAN-401).
    expect($viewer->can('view', $project))->toBeTrue()
        ->and($viewer->can('create-task', $project))->toBeFalse()
        ->and($viewer->can('manage-attachments', $project))->toBeFalse()
        ->and($viewer->can('archive-task', $project))->toBeFalse();
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
