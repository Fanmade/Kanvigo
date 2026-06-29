<?php

use App\Livewire\Admin\UserManagement;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('deactivates a user account', function () {
    $admin = User::factory()->canManageUsers()->create();
    $member = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->call('deactivate', $member->id);

    expect($member->fresh()->isDeactivated())->toBeTrue();
});

it('reactivates a deactivated account', function () {
    $admin = User::factory()->canManageUsers()->create();
    $member = User::factory()->deactivated()->create();

    Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->call('reactivate', $member->id);

    expect($member->fresh()->isDeactivated())->toBeFalse();
});

it('prevents an administrator from deactivating their own account', function () {
    $admin = User::factory()->canManageUsers()->create();

    Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->call('deactivate', $admin->id)
        ->assertForbidden();

    expect($admin->fresh()->isDeactivated())->toBeFalse();
});

it('blocks a deactivated user from accessing the application', function () {
    $member = User::factory()->deactivated()->create();

    actingAs($member)
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

it('blocks a deactivated user from the REST API even with a token', function () {
    $member = User::factory()->deactivated()->create();
    Sanctum::actingAs($member, ['read']);

    $this->getJson('/api/v1/user')->assertForbidden();
});

it('revokes personal access tokens when an account is deactivated', function () {
    $member = User::factory()->create();
    $member->createToken('cli', ['read', 'write']);

    expect($member->tokens()->count())->toBe(1);

    $member->deactivate();

    expect($member->tokens()->count())->toBe(0);
});

it('removes a user by soft-deleting and detaching their assignments', function () {
    $admin = User::factory()->canManageUsers()->create();
    $member = User::factory()->create();
    $task = Task::factory()->create();
    $member->assignedTasks()->attach($task);

    Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->call('confirmRemoval', $member->id)
        ->call('removeUser');

    expect(User::find($member->id))->toBeNull()
        ->and(User::withTrashed()->find($member->id))->not->toBeNull();

    $this->assertDatabaseMissing('task_user', [
        'user_id' => $member->id,
        'task_id' => $task->id,
    ]);
});

it('prevents an administrator from removing their own account', function () {
    $admin = User::factory()->canManageUsers()->create();

    Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->call('confirmRemoval', $admin->id)
        ->assertForbidden();

    expect(User::find($admin->id))->not->toBeNull();
});

it('keeps comments authored by a removed user but hides their identity', function () {
    $author = User::factory()->create();
    $task = Task::factory()->create();
    $comment = $task->comments()->create([
        'user_id' => $author->id,
        'body' => 'A lasting remark.',
    ]);

    $author->delete();

    $comment->refresh()->load('user');

    expect($comment->user_id)->toBe($author->id)
        ->and($comment->user)->toBeNull()
        ->and($comment->body)->toBe('A lasting remark.');
});

it('soft-deleted users cannot authenticate', function () {
    $member = User::factory()->create();
    $member->delete();

    expect(auth()->loginUsingId($member->id))->toBeFalse();
});
