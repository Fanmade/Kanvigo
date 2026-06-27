<?php

use App\Authorization\ProjectRoleProvisioner;
use App\Models\Project;
use App\Models\User;
use Fanmade\DelegatedPermissions\RoleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');

// Browser tests run the app, its JS bundle (Livewire, Flux, Tiptap) and a real
// browser all in-process, and the suite runs `--parallel`. Under that CPU
// contention an occasional page load or Livewire render overshoots Playwright's
// 5s default and a wait spuriously times out (e.g. login → dashboard). Raising
// the ceiling costs passing tests nothing — waits resolve the moment their
// condition is met — and only absorbs those load spikes.
pest()->browser()->timeout(15_000);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Grant one or more users membership of a project with one or more package
 * roles (default: member). Mirrors how the app provisions members (KAN-243): a
 * project_user row plus a delegated-permissions role assignment, so the members
 * resolve real project access through the package. Pass an array of role names
 * to hold several at once (KAN-317).
 *
 * @param  User|int|array<int, User|int>  $users
 * @param  string|list<string>  $role
 */
function joinProject(Project $project, User|int|array $users, string|array $role = 'member'): void
{
    $provisioner = app(ProjectRoleProvisioner::class);
    $roles = array_values((array) $role);

    foreach (is_array($users) ? $users : [$users] as $user) {
        $user = $user instanceof User ? $user : User::findOrFail($user);
        $project->members()->syncWithoutDetaching([$user->id]);
        $provisioner->syncMember($project, $user, $roles[0]);

        foreach (array_slice($roles, 1) as $extraRole) {
            $provisioner->addRole($project, $user, $extraRole);
        }
    }
}

/**
 * A user holding the named base project role (owner|admin|member|viewer).
 */
function userWithRole(Project $project, string $role): User
{
    $user = User::factory()->create();
    joinProject($project, $user, $role);

    return $user;
}

/**
 * A user holding a fresh custom project role that grants exactly the given
 * permissions (plus view-project, so they can reach the project at all).
 *
 * @param  list<string>  $permissions
 */
function userWithPermissions(Project $project, array $permissions): User
{
    $owner = app(ProjectRoleProvisioner::class)->roleFor($project, 'owner');
    $role = app(RoleManager::class)->createRole(
        'Custom '.fake()->unique()->word(),
        $owner,
        array_values(array_unique(['view-project', ...$permissions])),
        $project,
    );

    return User::factory()->create()->assignRole($role);
}
