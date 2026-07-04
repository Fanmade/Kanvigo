<?php

use App\Models\Project;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Membership alone grants nothing since the delegated-permissions adoption —
 * a seeded local environment must come with working project roles, or every
 * action (even viewing the demo project) 403s out of the box (KAN-406).
 */
it('seeds demo members with working project roles', function () {
    $this->seed(DemoSeeder::class);

    $project = Project::query()->where('short_name', 'KAN')->firstOrFail();
    $admin = User::query()->where('email', config('admin.email') ?: 'admin@example.com')->firstOrFail();
    $member = $project->members()->where('users.id', '!=', $admin->id)->firstOrFail();
    $task = $project->tasks()->firstOrFail();

    expect($admin->can('view-project', $project))->toBeTrue()
        ->and($admin->can('update', $task))->toBeTrue()
        ->and($member->can('view-project', $project))->toBeTrue()
        ->and($member->can('view', $task))->toBeTrue();
});
