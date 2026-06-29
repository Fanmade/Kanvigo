<?php

use App\Authorization\Exceptions\OwnerAlreadyAssigned;
use App\Authorization\ProjectRoleProvisioner;
use App\Models\Project;
use App\Models\User;
use Fanmade\DelegatedPermissions\Exceptions\RoleLimitExceeded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function provisioner(): ProjectRoleProvisioner
{
    return app(ProjectRoleProvisioner::class);
}

it('adds a role without disturbing the roles already held', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMember($user)->create();

    provisioner()->addRole($project, $user, 'admin');

    expect($project->roleNamesFor($user))->toBe(['admin', 'member']);
});

it('grants the union of permissions across held roles', function () {
    // viewer (read-only) plus a member role: the member's contribute perms apply.
    $user = User::factory()->create();
    $project = Project::factory()->withMember($user, ['viewer', 'member'])->create();

    expect($user->can('create-task', $project))->toBeTrue()
        ->and($user->can('view-project', $project))->toBeTrue();
});

it('removes a single role and leaves the rest intact', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMember($user, ['member', 'admin'])->create();

    provisioner()->removeRole($project, $user, 'admin');

    expect($project->roleNamesFor($user))->toBe(['member']);
});

it('is idempotent when adding a role the user already holds', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMember($user)->create();

    provisioner()->addRole($project, $user, 'member');

    expect($project->roleNamesFor($user))->toBe(['member']);
});

it('reports the highest-ranked role as the single role name', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMember($user, ['viewer', 'member', 'admin'])->create();

    expect($project->roleNameFor($user))->toBe('admin')
        ->and($project->roleNamesFor($user))->toBe(['admin', 'member', 'viewer']);
});

it('reads role names from an eager-loaded relation without querying', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMember($user, ['admin'])->create();

    // Load the user the way the member panel does — with the scoped roles eager-loaded.
    $loaded = $project->members()
        ->with(['roles' => static fn ($query) => $query
            ->where('scope_type', $project->getMorphClass())
            ->where('scope_id', $project->getKey())])
        ->whereKey($user->id)
        ->firstOrFail();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $names = $project->roleNamesFor($loaded);
    $owner = $project->isOwner($loaded);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Both reads come from the loaded relation — no extra queries.
    expect($queries)->toBe(0)
        ->and($names)->toBe(['admin'])
        ->and($owner)->toBeFalse();
});

it('falls back to a query when the roles relation is not loaded', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMember($user, ['admin'])->create();

    expect($project->roleNamesFor(User::findOrFail($user->id)))->toBe(['admin']);
});

it('enforces the one-owner-per-project rule', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->withOwner($owner)->create();
    $other = User::factory()->create();

    provisioner()->addRole($project, $other, 'owner');
})->throws(OwnerAlreadyAssigned::class);

it('lets the existing owner be re-granted owner idempotently', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->withOwner($owner)->create();

    provisioner()->addRole($project, $owner, 'owner');

    expect($project->roleNamesFor($owner))->toBe(['owner']);
});

it('rejects an assignment that exceeds the configured per-scope cap', function () {
    config()->set('delegated-permissions.max_roles_per_scope', 1);

    $user = User::factory()->create();
    $project = Project::factory()->withMember($user)->create();

    provisioner()->addRole($project, $user, 'admin');
})->throws(RoleLimitExceeded::class);
