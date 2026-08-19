<?php

use App\Authorization\ProjectRoleProvisioner;
use App\Enums\Permission;
use App\Livewire\Admin\UserManagement;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use Fanmade\DelegatedPermissions\RoleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * Determine whether the rendered button carrying the given data-test value has
 * a disabled attribute on its opening tag.
 */
function buttonIsDisabled(string $html, string $dataTest): bool
{
    $needle = 'data-test="'.$dataTest.'"';
    $position = strpos($html, $needle);

    if ($position === false) {
        return false;
    }

    $tagStart = strrpos(substr($html, 0, $position), '<');
    $tagEnd = strpos($html, '>', $position);
    $tag = substr($html, $tagStart, $tagEnd - $tagStart);

    // Match the rendered disabled attribute, not the Tailwind "disabled:" variant classes.
    return str_contains($tag, 'disabled="disabled"');
}

it('forbids users without the manage-users permission from reaching the area', function () {
    actingAs(User::factory()->create())
        ->get(route('admin.users'))
        ->assertForbidden();
});

it('allows administrators with the manage-users permission to reach the area', function () {
    actingAs(User::factory()->canManageUsers()->create())
        ->get(route('admin.users'))
        ->assertOk()
        ->assertSeeLivewire(UserManagement::class);
});

it('shows the navigation entry only to administrators who can manage users', function () {
    actingAs(User::factory()->canManageUsers()->create())
        ->get(route('dashboard'))
        ->assertSee('User administration');

    actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertDontSee('User administration');
});

it('lists all users with their status', function () {
    $admin = User::factory()->canManageUsers()->create(['name' => 'Ada Admin']);
    $member = User::factory()->create(['name' => 'Bob Member']);

    Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->assertSee('Ada Admin')
        ->assertSee('Bob Member');
});

it('grants a permission to a user', function () {
    $admin = User::factory()->canManageUsers()->create();
    $member = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->call('togglePermission', $member->id, Permission::CreateProjects->value);

    expect($member->fresh()->hasPermission(Permission::CreateProjects))->toBeTrue();
});

it('revokes a permission the user already has', function () {
    $admin = User::factory()->canManageUsers()->create();
    $member = User::factory()->canCreateProjects()->create();

    Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->call('togglePermission', $member->id, Permission::CreateProjects->value);

    expect($member->fresh()->hasPermission(Permission::CreateProjects))->toBeFalse();
});

it('prevents an administrator from revoking their own manage-users permission', function () {
    $admin = User::factory()->canManageUsers()->create();

    Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->call('togglePermission', $admin->id, Permission::ManageUsers->value);

    expect($admin->fresh()->hasPermission(Permission::ManageUsers))->toBeTrue();
});

it('renders the administrator\'s own manage-users toggle as disabled', function () {
    $admin = User::factory()->canManageUsers()->create();
    $other = User::factory()->canManageUsers()->create();

    $html = Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->html();

    expect($html)
        ->toContain('data-test="perm-'.$admin->id.'-'.Permission::ManageUsers->value.'"')
        // The own manage-users button is disabled; another admin's stays interactive.
        ->and(buttonIsDisabled($html, 'perm-'.$admin->id.'-'.Permission::ManageUsers->value))->toBeTrue()
        ->and(buttonIsDisabled($html, 'perm-'.$other->id.'-'.Permission::ManageUsers->value))->toBeFalse()
        ->and(buttonIsDisabled($html, 'perm-'.$admin->id.'-'.Permission::CreateProjects->value))->toBeFalse();
});

it('filters users by name or email', function () {
    $admin = User::factory()->canManageUsers()->create(['name' => 'Ada Admin', 'email' => 'ada@example.com']);
    User::factory()->create(['name' => 'Zoe Zebra', 'email' => 'zoe@example.com']);

    Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->set('search', 'Zoe')
        ->assertSee('Zoe Zebra')
        ->assertDontSee('Ada Admin');
});

it('resends a pending invitation and refreshes its expiry', function () {
    Mail::fake();

    $admin = User::factory()->canManageUsers()->create();
    $invitation = Invitation::forceCreate([
        'email' => 'invitee@example.com',
        'token' => 'a-token',
        'invited_by' => $admin->id,
        'project_ids' => [],
        'expires_at' => now()->addDay(),
    ]);

    Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->call('resendInvitation', $invitation->id);

    Mail::assertSent(InvitationMail::class, static fn (InvitationMail $mail): bool => $mail->hasTo('invitee@example.com'));
    expect($invitation->fresh()->expires_at->isAfter(now()->addDays(6)))->toBeTrue();
});

it('revokes a pending invitation', function () {
    $admin = User::factory()->canManageUsers()->create();
    $invitation = Invitation::forceCreate([
        'email' => 'invitee@example.com',
        'token' => 'a-token',
        'invited_by' => $admin->id,
        'project_ids' => [],
        'expires_at' => now()->addDay(),
    ]);

    Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->call('revokeInvitation', $invitation->id);

    $this->assertDatabaseMissing('invitations', ['id' => $invitation->id]);
});

it('shows each user the count of pending invitations they have sent', function () {
    $admin = User::factory()->canManageUsers()->create();

    Invitation::forceCreate([
        'email' => 'pending@example.com',
        'token' => 'tok-pending',
        'invited_by' => $admin->id,
        'project_ids' => [],
        'expires_at' => now()->addDay(),
    ]);

    // An accepted invitation is no longer pending and must not be counted.
    $accepted = Invitation::forceCreate([
        'email' => 'accepted@example.com',
        'token' => 'tok-accepted',
        'invited_by' => $admin->id,
        'project_ids' => [],
        'expires_at' => now()->addDay(),
    ]);
    $accepted->accepted_at = now();
    $accepted->save();

    // An expired invitation is no longer usable and must not be counted.
    Invitation::forceCreate([
        'email' => 'expired@example.com',
        'token' => 'tok-expired',
        'invited_by' => $admin->id,
        'project_ids' => [],
        'expires_at' => now()->subDay(),
    ]);

    $html = Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->html();

    expect($html)
        ->toContain('data-test="pending-invites-'.$admin->id.'"')
        ->toContain('1 pending');
});

it('omits the pending-invitations badge for users who have sent none', function () {
    $admin = User::factory()->canManageUsers()->create();
    $member = User::factory()->create();

    $html = Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->html();

    expect($html)->not->toContain('data-test="pending-invites-'.$member->id.'"');
});

it('forbids a non-administrator from mounting the management component', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(UserManagement::class)
        ->assertForbidden();
});

it('assigns and removes a named account role', function () {
    $admin = User::factory()->create();
    $admin->syncPermissions([Permission::ManageUsers]);
    $member = User::factory()->create();

    $role = app(RoleManager::class)->createRole(
        'User manager',
        app(ProjectRoleProvisioner::class)->systemRole(),
        [Permission::InviteUsers->value, Permission::ManageUsers->value],
    );

    $component = Livewire::actingAs($admin->fresh())
        ->test(UserManagement::class)
        ->call('toggleRole', $member->id, $role->id);

    expect($member->fresh()->hasPermission(Permission::InviteUsers))->toBeTrue();

    $component->call('toggleRole', $member->id, $role->id);

    expect($member->fresh()->hasPermission(Permission::InviteUsers))->toBeFalse();
});

it('separates a directly granted permission from one held through a role', function () {
    $admin = User::factory()->create();
    $admin->syncPermissions([Permission::ManageUsers]);

    $member = User::factory()->create();
    $member->syncPermissions([Permission::CreateProjects]);

    $role = app(RoleManager::class)->createRole(
        'Recruiter',
        app(ProjectRoleProvisioner::class)->systemRole(),
        [Permission::InviteUsers->value],
    );
    $member->assignRole($role);

    $component = Livewire::actingAs($admin->fresh())->test(UserManagement::class);
    $instance = $component->instance();
    $subject = $member->fresh()->load('roles');

    expect($instance->hasDirectPermission($subject, Permission::CreateProjects))->toBeTrue()
        ->and($instance->hasDirectPermission($subject, Permission::InviteUsers))->toBeFalse()
        ->and($instance->rolesGranting($subject, Permission::InviteUsers))->toBe(['Recruiter'])
        ->and($instance->rolesOf($subject)->pluck('name')->all())->toBe(['Recruiter']);

    // The role-derived permission renders as a locked chip, not a toggle.
    $component->assertSeeHtml('perm-role-'.$member->id.'-'.Permission::InviteUsers->value);
});
