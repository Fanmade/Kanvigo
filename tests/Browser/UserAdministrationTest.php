<?php

use App\Enums\Permission;
use App\Models\Invitation;
use App\Models\User;

it('loads the user administration area with the account list and pending invitations', function () {
    $admin = User::factory()->canManageUsers()->create(['name' => 'Ada Admin']);
    $member = User::factory()->create(['name' => 'Bob Member']);

    // A still-valid invitation the admin has sent surfaces as a per-user badge
    // and in the pending-invitations list.
    $invitation = Invitation::create([
        'email' => 'invitee@example.com',
        'token' => 'a-token',
        'invited_by' => $admin->id,
        'project_ids' => [],
        'expires_at' => now()->addDay(),
    ]);

    $this->actingAs($admin);

    $page = visit('/admin/users');

    $page->assertVisible('@user-row-'.$admin->id)
        ->assertVisible('@user-row-'.$member->id)
        ->assertVisible('@pending-invites-'.$admin->id)
        ->assertVisible('@invitation-row-'.$invitation->id)
        ->assertNoJavascriptErrors();
});

it('toggles a permission for a user from the browser', function () {
    $admin = User::factory()->canManageUsers()->create();
    $member = User::factory()->create();

    $this->actingAs($admin);

    $page = visit('/admin/users');

    // The member starts without the create-projects permission.
    expect($member->fresh()->hasPermission(Permission::CreateProjects))->toBeFalse();

    $page->click('@perm-'.$member->id.'-'.Permission::CreateProjects->value)
        ->waitForText('Permissions updated.') // barrier: the toggle round-trip completed
        ->assertNoJavascriptErrors();

    expect($member->fresh()->hasPermission(Permission::CreateProjects))->toBeTrue();
});

it('deactivates and reactivates a user from the browser', function () {
    $admin = User::factory()->canManageUsers()->create();
    $member = User::factory()->create();

    $this->actingAs($admin);

    $page = visit('/admin/users');

    $page->assertVisible('@deactivate-'.$member->id)
        ->click('@deactivate-'.$member->id)
        ->assertVisible('@reactivate-'.$member->id)
        ->assertNoJavascriptErrors();

    expect($member->fresh()->isDeactivated())->toBeTrue();

    $page->click('@reactivate-'.$member->id)
        ->assertVisible('@deactivate-'.$member->id)
        ->assertNoJavascriptErrors();

    expect($member->fresh()->isDeactivated())->toBeFalse();
});

it('removes a user through the confirmation modal', function () {
    $admin = User::factory()->canManageUsers()->create();
    $member = User::factory()->create();

    $this->actingAs($admin);

    $page = visit('/admin/users');

    $page->assertMissing('@confirm-remove')
        ->click('@remove-'.$member->id)
        ->assertVisible('@confirm-remove')
        ->click('@confirm-remove')
        ->assertMissing('@user-row-'.$member->id) // barrier: the row is gone once the removal applied
        ->assertNoJavascriptErrors();

    // Removal is a soft delete; the row disappears from the scoped query.
    $this->assertSoftDeleted($member);
});
