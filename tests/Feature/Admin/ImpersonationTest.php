<?php

use App\Enums\Permission;
use App\Livewire\Admin\UserManagement;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

/**
 * The decoded audit events currently in the outbox, oldest first, optionally
 * filtered to one action.
 *
 * @return Collection<int, array<string, mixed>>
 */
function impersonationAuditEvents(?string $action = null): Collection
{
    $events = DB::table('audit_outbox')->orderBy('id')->get()
        ->map(static fn (object $row): array => json_decode((string) $row->event, true, flags: JSON_THROW_ON_ERROR));

    return $action === null ? $events : $events->where('action', $action)->values();
}

it('lets an administrator with the permission act as another account', function () {
    $administrator = User::factory()->canImpersonateUsers()->create();
    $target = User::factory()->create();

    actingAs($administrator)
        ->post(route('impersonation.store', $target))
        ->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($target->id)
        ->and(session(Impersonation::SESSION_KEY))->toBe($administrator->id);
});

it('refuses an administrator without the permission', function () {
    $administrator = User::factory()->canManageUsers()->create();

    actingAs($administrator)
        ->post(route('impersonation.store', User::factory()->create()))
        ->assertForbidden();

    expect(session()->has(Impersonation::SESSION_KEY))->toBeFalse();
});

it('refuses to impersonate yourself', function () {
    $administrator = User::factory()->canImpersonateUsers()->create();

    actingAs($administrator)
        ->post(route('impersonation.store', $administrator))
        ->assertForbidden();
});

it('refuses a deactivated target', function () {
    $administrator = User::factory()->canImpersonateUsers()->create();
    $target = User::factory()->create(['deactivated_at' => now()]);

    actingAs($administrator)
        ->post(route('impersonation.store', $target))
        ->assertForbidden();
});

it('refuses a target who can impersonate or manage account roles', function (Permission $permission) {
    $administrator = User::factory()->canImpersonateUsers()->create();
    $target = User::factory()->withPermission($permission)->create();

    actingAs($administrator)
        ->post(route('impersonation.store', $target))
        ->assertForbidden();
})->with([
    'another impersonator' => Permission::ImpersonateUsers,
    'an account-role manager' => Permission::ManageAccountRoles,
]);

it('refuses to nest one impersonation inside another', function () {
    $administrator = User::factory()->canImpersonateUsers()->create();

    actingAs($administrator)->post(route('impersonation.store', User::factory()->create()));

    post(route('impersonation.store', User::factory()->create()))->assertForbidden();
});

it('hands the administrator back when impersonation stops', function () {
    $administrator = User::factory()->canImpersonateUsers()->create();
    $target = User::factory()->create();

    actingAs($administrator)->post(route('impersonation.store', $target));

    post(route('impersonation.stop'))->assertRedirect(route('admin.users'));

    expect(auth()->id())->toBe($administrator->id)
        ->and(session()->has(Impersonation::SESSION_KEY))->toBeFalse()
        ->and(session()->has(Impersonation::PASSWORD_CONFIRMED_KEY))->toBeFalse();
});

it('does nothing when stopping without an impersonation in progress', function () {
    actingAs(User::factory()->create())
        ->post(route('impersonation.stop'))
        ->assertRedirect(route('dashboard'));
});

it('leaves the impersonated account\'s remember token alone', function () {
    $administrator = User::factory()->canImpersonateUsers()->create();
    $target = User::factory()->create(['remember_token' => 'keep-me-signed-in']);

    actingAs($administrator)->post(route('impersonation.store', $target));
    post(route('impersonation.stop'));

    expect($target->fresh()->remember_token)->toBe('keep-me-signed-in');
});

it('parks the administrator\'s password confirmation and restores it', function () {
    $administrator = User::factory()->canImpersonateUsers()->create();
    $target = User::factory()->create();

    actingAs($administrator)
        ->withSession(['auth.password_confirmed_at' => 1_700_000_000])
        ->post(route('impersonation.store', $target));

    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();

    post(route('impersonation.stop'));

    expect(session('auth.password_confirmed_at'))->toBe(1_700_000_000);
});

it('audits the start and the end of the window', function () {
    $administrator = User::factory()->canImpersonateUsers()->create();
    $target = User::factory()->create();

    actingAs($administrator)->post(route('impersonation.store', $target));
    post(route('impersonation.stop'));

    $started = impersonationAuditEvents('impersonation_started')->last();
    $stopped = impersonationAuditEvents('impersonation_stopped')->last();

    expect($started['category'])->toBe('security')
        ->and($started['actor_id'])->toBe($administrator->id)
        ->and($started['subject_id'])->toBe($administrator->id)
        ->and($started['metadata']['member_id'])->toBe($target->id)
        ->and($started['metadata']['member'])->toBe($target->name)
        ->and($started['metadata'])->not->toHaveKey('impersonator_id')
        ->and($stopped['category'])->toBe('security')
        ->and($stopped['actor_id'])->toBe($administrator->id)
        ->and($stopped['metadata']['member_id'])->toBe($target->id);
});

it('marks everything recorded during the window with the administrator behind it', function () {
    $administrator = User::factory()->canImpersonateUsers()->create();
    $target = User::factory()->create();

    actingAs($administrator)->post(route('impersonation.store', $target));

    $task = Task::factory()->for(Project::factory()->create())->create();

    $created = impersonationAuditEvents('created')
        ->where('subject_id', $task->id)
        ->last();

    expect($created['metadata']['impersonator_id'])->toBe($administrator->id)
        ->and($created['tags'])->toContain('impersonated');
});

it('shows the banner and the account-menu exit only while impersonating', function () {
    $administrator = User::factory()->canImpersonateUsers()->create();
    $target = User::factory()->create();

    actingAs($administrator);

    expect(view('components.impersonation-banner')->render())
        ->not->toContain('impersonation-banner')
        ->and((string) $this->blade('<x-account-menu-items test-prefix="header-account" />'))
        ->not->toContain('header-account-stop-impersonating');

    $this->post(route('impersonation.store', $target));

    expect(view('components.impersonation-banner')->render())
        ->toContain('impersonation-banner')
        ->and((string) $this->blade('<x-account-menu-items test-prefix="header-account" />'))
        ->toContain('header-account-stop-impersonating');
});

it('offers the impersonate button only to an administrator who may use it', function () {
    $target = User::factory()->create();

    $rendered = static fn (User $administrator): string => Livewire::actingAs($administrator)
        ->test(UserManagement::class)
        ->html();

    expect($rendered(User::factory()->canManageUsers()->canImpersonateUsers()->create()))
        ->toContain('impersonate-'.$target->id)
        ->and($rendered(User::factory()->canManageUsers()->create()))
        ->not->toContain('impersonate-'.$target->id);
});

it('can be stopped even when the impersonated account is unverified', function () {
    $administrator = User::factory()->canImpersonateUsers()->create();
    $target = User::factory()->unverified()->create();

    actingAs($administrator)->post(route('impersonation.store', $target));

    post(route('impersonation.stop'))->assertRedirect(route('admin.users'));

    expect(auth()->id())->toBe($administrator->id);
});
