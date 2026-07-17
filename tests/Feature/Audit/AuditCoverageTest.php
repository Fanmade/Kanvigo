<?php

use App\Actions\AddProjectMember;
use App\Actions\ConvertNote;
use App\Actions\RemoveProjectMember;
use App\Authorization\AccountPermissionProvisioner;
use App\Authorization\ProjectRoleProvisioner;
use App\Enums\Permission as AccountPermission;
use App\Enums\Priority;
use App\Livewire\Admin\UserManagement;
use App\Livewire\Invitations\AcceptInvitation;
use App\Livewire\Invitations\InviteUser;
use App\Livewire\Projects\ProjectRoles;
use App\Livewire\Settings\ApiTokens;
use App\Livewire\Settings\DeleteUserForm;
use App\Livewire\Settings\Passkeys;
use App\Livewire\Settings\Security;
use App\Livewire\Settings\TwoFactor;
use App\Livewire\Settings\TwoFactor\RecoveryCodes;
use App\Models\Attachment;
use App\Models\Invitation;
use App\Models\Note;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Fanmade\DelegatedPermissions\Models\Permission;
use Fanmade\DelegatedPermissions\Models\Role;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Audit coverage matrix (KAN-395)
|--------------------------------------------------------------------------
|
| Every security-relevant action must produce an audit record. Each test
| performs the real action and asserts the expected event landed in the
| audit outbox — the always-on durable record every sink feeds from — so a
| future code path that skips the audit layer fails here. The tests assert
| the observable outcome (an event exists), not which sink or mechanism
| produced it.
*/

/**
 * The decoded audit events currently in the outbox, oldest first, optionally
 * filtered to one action.
 *
 * @return Collection<int, array<string, mixed>>
 */
function auditOutboxEvents(?string $action = null): Collection
{
    $events = DB::table('audit_outbox')->orderBy('id')->get()
        ->map(static fn (object $row): array => json_decode((string) $row->event, true, flags: JSON_THROW_ON_ERROR));

    return $action === null ? $events : $events->where('action', $action)->values();
}

/**
 * Assert at least one audit event with the given action (and category) was
 * recorded, returning the latest one for further assertions.
 *
 * @return array<string, mixed>
 */
function assertAudited(string $action, ?string $category = null): array
{
    $events = auditOutboxEvents($action);

    expect($events)->not->toBeEmpty("Expected an audit event for [$action], none was recorded.");

    $event = $events->last();

    if ($category !== null) {
        expect($event['category'])->toBe($category);
    }

    return $event;
}

describe('authentication events', function () {
    it('audits a successful login', function () {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $event = assertAudited('login', 'authn');
        expect($event['actor_id'])->toBe($user->id);
    });

    it('audits a logout', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('logout'));

        $event = assertAudited('logout', 'authn');
        expect($event['actor_id'])->toBe($user->id);
    });

    it('audits a failed login attempt', function () {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);

        $event = assertAudited('login_failed', 'authn');
        expect($event['metadata']['email'])->toBe($user->email);
    });

    it('audits a login lockout', function () {
        $user = User::factory()->create();

        foreach (range(1, 6) as $attempt) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $event = assertAudited('lockout', 'security');
        expect($event['metadata']['email'])->toBe($user->email);
    });

    it('audits a password reset', function () {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])->assertSessionHasNoErrors();

            return true;
        });

        $event = assertAudited('password_reset', 'authn');
        expect($event['subject_id'])->toBe($user->id);
    });

    it('audits a password change from the security settings', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Security::class)
            ->set('current_password', 'password')
            ->set('password', 'new-password-123')
            ->set('password_confirmation', 'new-password-123')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $event = assertAudited('password_changed', 'authn');
        expect($event['actor_id'])->toBe($user->id);
    });

    it('audits an email verification', function () {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $this->actingAs($user)->get($verificationUrl);

        $event = assertAudited('email_verified', 'authn');
        expect($event['subject_id'])->toBe($user->id);
    });

    it('audits registration when an invitation is accepted', function () {
        $inviter = User::factory()->canInviteUsers()->create();
        $project = Project::factory()->create();
        joinProject($project, $inviter);

        $invitation = Invitation::forceCreate([
            'email' => 'new@example.com',
            'token' => 'secret-token',
            'invited_by' => $inviter->id,
            'project_ids' => [$project->id],
            'expires_at' => now()->addDay(),
        ]);

        Livewire::withQueryParams(['token' => 'secret-token'])
            ->test(AcceptInvitation::class, ['invitation' => $invitation])
            ->set('name', 'Newbie')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('accept');

        $user = User::where('email', 'new@example.com')->firstOrFail();

        expect(assertAudited('registered', 'authn')['subject_id'])->toBe($user->id)
            ->and(assertAudited('invitation_accepted', 'authz')['actor_id'])->toBe($user->id)
            ->and(assertAudited('member_added', 'authz')['metadata']['member_id'])->toBe($user->id);
    });

    it('audits the two-factor lifecycle: enable, confirm, disable', function () {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(TwoFactor::class)
            ->call('enable');

        assertAudited('two_factor_enabled', 'authn');

        $code = app(Google2FA::class)->getCurrentOtp(decrypt($user->refresh()->two_factor_secret));

        $component->set('code', $code)->call('confirmTwoFactor')->assertHasNoErrors();

        assertAudited('two_factor_confirmed', 'authn');

        Livewire::actingAs($user)
            ->test(RecoveryCodes::class)
            ->call('regenerateRecoveryCodes');

        assertAudited('recovery_codes_generated', 'authn');

        $component->call('disable');

        assertAudited('two_factor_disabled', 'authn');
    });

    it('audits a two-factor challenge and a failed challenge code', function () {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => encrypt(app(Google2FA::class)->generateSecretKey()),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-one'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.login'));

        assertAudited('two_factor_challenged', 'authn');

        $this->post('/two-factor-challenge', ['code' => '000000']);

        $event = assertAudited('two_factor_failed', 'authn');
        expect($event['subject_id'])->toBe($user->id);
    });

    it('audits passkey registration and verification', function () {
        // The WebAuthn attestation/assertion ceremony can't run in a feature
        // test, so the package events are dispatched as the controllers would.
        $user = User::factory()->create();
        $passkey = $user->passkeys()->create([
            'name' => 'Phone',
            'credential_id' => 'cred-1',
            'credential' => ['publicKey' => 'test'],
        ]);

        event(new PasskeyRegistered($user, $passkey));
        event(new PasskeyVerified($user, $passkey));

        expect(assertAudited('passkey_registered', 'authn')['metadata']['passkey'])->toBe('Phone')
            ->and(assertAudited('passkey_verified', 'authn')['subject_id'])->toBe($user->id);
    });

    it('audits a passkey deletion', function () {
        $user = User::factory()->create();
        $passkey = $user->passkeys()->create([
            'name' => 'Phone',
            'credential_id' => 'cred-1',
            'credential' => ['publicKey' => 'test'],
        ]);

        Livewire::actingAs($user)
            ->test(Passkeys::class)
            ->call('confirmDelete', $passkey->id)
            ->call('deletePasskey');

        expect(assertAudited('passkey_deleted', 'authn')['metadata']['passkey_id'])->toBe($passkey->id);
    });
});

describe('authorization and membership events', function () {
    it('audits adding and removing a project member, with their role changes', function () {
        $project = Project::factory()->create();
        $member = User::factory()->create();

        app(AddProjectMember::class)->handle($project, $member);

        expect(assertAudited('member_added', 'authz')['metadata']['member_id'])->toBe($member->id)
            ->and(assertAudited('role_assigned', 'authz')['metadata']['role'])->toBe('member');

        app(RemoveProjectMember::class)->handle($project, $member);

        expect(assertAudited('member_removed', 'authz')['metadata']['member_id'])->toBe($member->id)
            ->and(assertAudited('role_removed', 'authz')['metadata']['replaced'])->toBe(['member']);
    });

    it('audits granting and revoking an account permission, once per actual change', function () {
        $user = User::factory()->create();
        $provisioner = app(AccountPermissionProvisioner::class);

        $provisioner->grant($user, AccountPermission::InviteUsers);
        $provisioner->grant($user, AccountPermission::InviteUsers);

        $granted = auditOutboxEvents('permission_granted');
        expect($granted)->toHaveCount(1)
            ->and($granted->first()['metadata']['permission'])->toBe(AccountPermission::InviteUsers->value)
            ->and($granted->first()['subject_id'])->toBe($user->id);

        $provisioner->revoke($user, AccountPermission::InviteUsers);
        $provisioner->revoke($user, AccountPermission::InviteUsers);

        expect(auditOutboxEvents('permission_revoked'))->toHaveCount(1);
    });

    it('audits custom role creation, permission edits and deletion', function () {
        $project = Project::factory()->create();
        $owner = userWithRole($project, 'owner');
        $ownerRole = app(ProjectRoleProvisioner::class)->roleFor($project, 'owner');
        $viewId = Permission::query()->where('name', 'view-project')->value('id');
        $logId = Permission::query()->where('name', 'view-activity-log')->value('id');

        $component = Livewire::actingAs($owner)
            ->test(ProjectRoles::class, ['project' => $project])
            ->set('name', 'Auditor')
            ->set('parentId', $ownerRole->id)
            ->set('permissionIds', [$viewId])
            ->call('createRole');

        expect(assertAudited('role_created', 'authz')['metadata']['role'])->toBe('Auditor');

        $role = Role::query()->where('name', 'Auditor')->firstOrFail();

        $component->call('startEdit', $role->id)
            ->set('editPermissionIds', [$viewId, $logId])
            ->call('saveRole');

        expect(assertAudited('role_updated', 'authz')['metadata']['granted'])->toBe(['view-activity-log']);

        $component->call('deleteRole', $role->id);

        expect(assertAudited('role_deleted', 'authz')['metadata']['role'])->toBe('Auditor');
    });

    it('audits creating, resending and revoking an invitation', function () {
        Mail::fake();

        $inviter = User::factory()->canInviteUsers()->create();

        Livewire::actingAs($inviter)
            ->test(InviteUser::class)
            ->set('email', 'new@example.com')
            ->call('sendInvitation');

        expect(assertAudited('invitation_created', 'authz')['metadata']['email'])->toBe('new@example.com');

        $admin = User::factory()->create();
        app(AccountPermissionProvisioner::class)->grant($admin, AccountPermission::ManageUsers);
        $invitation = Invitation::firstOrFail();

        Livewire::actingAs($admin)
            ->test(UserManagement::class)
            ->call('resendInvitation', $invitation->id)
            ->call('revokeInvitation', $invitation->id);

        expect(assertAudited('invitation_resent', 'authz')['metadata']['email'])->toBe('new@example.com')
            ->and(assertAudited('invitation_revoked', 'authz')['metadata']['email'])->toBe('new@example.com');
    });
});

describe('content events', function () {
    it('audits task title, description, due date and priority edits on any write path', function () {
        $project = Project::factory()->create();
        $task = Task::factory()->for($project)->create(['title' => 'Old title']);

        $task->update([
            'title' => 'New title',
            'description' => '<p>New description</p>',
            'due_date' => '2026-08-01',
            'priority' => Priority::Highest,
        ]);

        $title = assertAudited('title_changed', 'content');
        expect($title['metadata']['old'])->toBe('Old title')
            ->and($title['metadata']['new'])->toBe('New title');

        // Free text stays out of the trail: the change is recorded, the content is not.
        $description = assertAudited('description_changed', 'content');
        expect($description['metadata'])->toBe(['field' => 'description']);

        expect(assertAudited('due_date_changed', 'content')['metadata']['new'])->toBe('2026-08-01');
        assertAudited('priority_changed', 'content');
    });

    it('audits project field edits', function () {
        $project = Project::factory()->create(['short_name' => 'OLDN']);

        $project->update([
            'title' => 'New project title',
            'short_name' => 'NEWN',
            'description' => '<p>New</p>',
        ]);

        expect(assertAudited('short_name_changed', 'content')['metadata']['new'])->toBe('NEWN');
        assertAudited('title_changed', 'content');
        assertAudited('description_changed', 'content');
    });

    it('audits the note lifecycle: create, edit, convert, delete', function () {
        $project = Project::factory()->create();
        $task = Task::factory()->for($project)->create();
        $note = Note::factory()->create();

        expect(assertAudited('note_created', 'content')['subject_id'])->toBe($note->id);

        $note->update(['title' => 'Renamed']);

        expect(assertAudited('note_updated', 'content')['metadata']['fields'])->toBe(['title']);

        app(ConvertNote::class)->handle($note, $task);

        expect(assertAudited('note_converted', 'content')['metadata']['task_id'])->toBe($task->id);

        $note->delete();

        expect(assertAudited('note_deleted', 'content')['subject_id'])->toBe($note->id);
    });

    it('audits task and project deletion', function () {
        $project = Project::factory()->create();
        $task = Task::factory()->for($project)->create();

        $task->delete();

        $taskEvent = auditOutboxEvents('deleted')->where('subject_type', Task::class)->last();
        expect($taskEvent)->not->toBeNull()
            ->and($taskEvent['subject_id'])->toBe($task->id);

        $project->delete();

        $projectEvent = auditOutboxEvents('deleted')->where('subject_type', Project::class)->last();
        expect($projectEvent)->not->toBeNull()
            ->and($projectEvent['subject_id'])->toBe($project->id);
    });

    it('audits a comment deletion through the REST API', function () {
        $user = User::factory()->create();
        $project = Project::factory()->create(['short_name' => 'ABC']);
        joinProject($project, $user);
        $task = Task::factory()->for($project)->create();
        $comment = $task->comments()->create(['user_id' => $user->id, 'body' => '<p>Bye</p>']);

        Sanctum::actingAs($user, ['read', 'write']);

        $this->deleteJson("/api/v1/comments/{$comment->id}", ['delete_reason' => 'Off topic'])
            ->assertNoContent();

        $event = assertAudited('comment_deleted', 'content');
        expect($event['metadata']['new'])->toBe('Off topic');
    });
});

describe('token and account lifecycle events', function () {
    it('audits API token creation and revocation', function () {
        $user = User::factory()->create();
        app(AccountPermissionProvisioner::class)->grant($user, AccountPermission::CreateApiTokens);

        $component = Livewire::actingAs($user)
            ->test(ApiTokens::class)
            ->set('name', 'CI token')
            ->set('accessLevel', 'write')
            ->call('createToken');

        expect(assertAudited('token_created', 'token')['metadata']['token'])->toBe('CI token');

        $component->call('revoke', $user->tokens()->firstOrFail()->id);

        expect(assertAudited('token_revoked', 'token')['metadata']['token'])->toBe('CI token');
    });

    it('audits deactivation (revoking tokens), reactivation and deletion of an account', function () {
        $user = User::factory()->create();
        $user->createToken('Old token', ['read']);

        $user->deactivate();

        expect(assertAudited('user_deactivated', 'security')['subject_id'])->toBe($user->id)
            ->and(assertAudited('token_revoked', 'token')['metadata']['reason'])->toBe('deactivated');

        $user->reactivate();

        assertAudited('user_reactivated', 'security');

        $user->delete();

        expect(assertAudited('user_deleted', 'security')['metadata']['force'])->toBeFalse();
    });

    it('audits a self-service account deletion attributed to the user', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(DeleteUserForm::class)
            ->set('password', 'password')
            ->call('deleteUser');

        // Force-deleted for real — the post-delete logout must not resurrect the row.
        expect(User::withTrashed()->find($user->id))->toBeNull();

        $event = assertAudited('user_deleted', 'security');
        expect($event['metadata']['force'])->toBeTrue()
            ->and($event['actor_id'])->toBe($user->id);
    });
});

describe('read and access events', function () {
    it('audits an attachment download through the REST API', function () {
        Storage::fake(config('attachments.disk'));
        $user = User::factory()->create();
        $project = Project::factory()->create(['short_name' => 'ABC']);
        joinProject($project, $user);
        $task = Task::factory()->for($project)->create();

        Sanctum::actingAs($user, ['read', 'write']);

        $id = $this->post(
            "/api/v1/tasks/{$task->reference}/attachments",
            ['file' => UploadedFile::fake()->create('secret.pdf', 16, 'application/pdf')],
            ['Accept' => 'application/json'],
        )->json('data.id');

        $this->get("/api/v1/attachments/{$id}")->assertOk();

        $event = assertAudited('attachment_downloaded', 'access');
        expect($event['subject_type'])->toBe((new Attachment)->getMorphClass())
            ->and($event['subject_id'])->toBe($id)
            ->and($event['metadata']['name'])->toBe('secret.pdf')
            ->and($event['actor_id'])->toBe($user->id);
    });

    it("audits one member viewing another member's contact info", function () {
        $viewer = User::factory()->create();
        $target = User::factory()->create();
        $project = Project::factory()->create(['short_name' => 'ABC']);
        joinProject($project, $viewer);
        joinProject($project, $target);

        Sanctum::actingAs($viewer, ['read']);

        $this->getJson("/api/v1/users/{$target->public_id}")
            ->assertOk()
            ->assertJsonPath('data.email', $target->email);

        $event = assertAudited('contact_info_viewed', 'access');
        expect($event['metadata']['member_id'])->toBe($target->id)
            ->and($event['metadata']['member'])->toBe($target->name)
            ->and($event['actor_id'])->toBe($viewer->id)
            ->and($event['subject_id'])->toBeNull();
    });

    it('does not audit a member viewing their own contact info', function () {
        $user = User::factory()->create();

        Sanctum::actingAs($user, ['read']);

        $this->getJson('/api/v1/user')->assertOk();
        $this->getJson("/api/v1/users/{$user->public_id}")->assertOk();

        expect(auditOutboxEvents('contact_info_viewed'))->toBeEmpty();
    });

    it('audits a read of the audit export stream', function () {
        $operator = User::factory()->canManageUsers()->create();
        Project::factory()->withMembers([$operator])->create();

        Auth::forgetGuards();

        $this->withToken($operator->createToken('SIEM', ['audit'])->plainTextToken)
            ->getJson('/api/v1/audit-events?limit=50')
            ->assertOk();

        $event = assertAudited('audit_stream_read', 'access');
        expect($event['metadata']['after'])->toBe(0)
            ->and($event['metadata']['limit'])->toBe(50)
            ->and($event['metadata'])->toHaveKey('returned')
            ->and($event['actor_id'])->toBe($operator->id);
    });
});
