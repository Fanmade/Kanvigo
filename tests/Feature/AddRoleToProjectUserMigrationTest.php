<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The migration runs DDL, which cannot live inside RefreshDatabase's wrapping
// transaction. Rebuild the schema fresh around each test instead.
beforeEach(fn () => Artisan::call('migrate:fresh'));
afterEach(fn () => Artisan::call('migrate:fresh'));

/**
 * Recreate the membership-only `project_user` pivot as it was before roles.
 */
function rebuildRolelessProjectUserSchema(): void
{
    Schema::dropIfExists('project_user');

    Schema::create('project_user', static function (Blueprint $table): void {
        $table->id();
        $table->foreignId('project_id')->constrained()->cascadeOnDelete();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->timestamps();
        $table->unique(['project_id', 'user_id']);
    });
}

function loadAddRoleMigration(): object
{
    return require database_path('migrations/2026_06_23_121602_add_role_to_project_user_table.php');
}

function addMembership(int $projectId, int $userId): int
{
    return DB::table('project_user')->insertGetId([
        'project_id' => $projectId,
        'user_id' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('backfills the earliest member of each project as owner and the rest as members', function () {
    rebuildRolelessProjectUserSchema();

    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $creator = User::factory()->create();
    $second = User::factory()->create();
    $third = User::factory()->create();
    $soleMember = User::factory()->create();

    // Insertion order sets the id order — the creator's membership is earliest.
    addMembership($project->id, $creator->id);
    addMembership($project->id, $second->id);
    addMembership($project->id, $third->id);
    addMembership($otherProject->id, $soleMember->id);

    loadAddRoleMigration()->up();

    $roleOf = static fn (int $projectId, int $userId): ?string => DB::table('project_user')
        ->where('project_id', $projectId)->where('user_id', $userId)->value('role');

    expect($roleOf($project->id, $creator->id))->toBe('owner')
        ->and($roleOf($project->id, $second->id))->toBe('member')
        ->and($roleOf($project->id, $third->id))->toBe('member')
        // Each project is backfilled independently.
        ->and($roleOf($otherProject->id, $soleMember->id))->toBe('owner');
});

it('defaults new memberships to member', function () {
    rebuildRolelessProjectUserSchema();

    loadAddRoleMigration()->up();

    $project = Project::factory()->create();
    $user = User::factory()->create();
    addMembership($project->id, $user->id);

    expect(DB::table('project_user')->where('user_id', $user->id)->value('role'))->toBe('member');
});
