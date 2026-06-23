<?php

use App\Livewire\Invitations\AcceptInvitation;
use App\Livewire\Invitations\InviteUser;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

it('forbids users without the invite capability', function () {
    actingAs(User::factory()->create())->get('/invite')->assertForbidden();
});

it('lets an authorised inviter send an invitation and mails a signed link', function () {
    Mail::fake();

    $inviter = User::factory()->canInviteUsers()->create();
    $project = Project::factory()->create();
    joinProject($project, $inviter);

    Livewire::actingAs($inviter)
        ->test(InviteUser::class)
        ->set('email', 'new@example.com')
        ->set('projectIds', [$project->id])
        ->call('sendInvitation');

    $invitation = Invitation::first();

    expect($invitation->email)->toBe('new@example.com')
        ->and($invitation->project_ids)->toBe([$project->id]);

    Mail::assertSent(InvitationMail::class);
});

it('cannot grant access to projects the inviter does not belong to', function () {
    Mail::fake();

    $inviter = User::factory()->canInviteUsers()->create();
    $foreign = Project::factory()->create();

    Livewire::actingAs($inviter)
        ->test(InviteUser::class)
        ->set('email', 'new@example.com')
        ->set('projectIds', [$foreign->id])
        ->call('sendInvitation');

    expect(Invitation::first()->project_ids)->toBe([]);
});

it('accepts a valid invitation and creates a verified, project-scoped user', function () {
    $inviter = User::factory()->canInviteUsers()->create();
    $project = Project::factory()->create();
    joinProject($project, $inviter);

    $invitation = Invitation::create([
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
        ->call('accept')
        ->assertRedirect(route('security.edit'));

    $user = User::where('email', 'new@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->projects()->whereKey($project->id)->exists())->toBeTrue()
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();
});

it('rejects an already accepted invitation through the signed route', function () {
    $invitation = Invitation::create([
        'email' => 'used@example.com',
        'token' => 'tok',
        'invited_by' => User::factory()->create()->id,
        'project_ids' => [],
        'expires_at' => now()->addDay(),
    ]);
    $invitation->forceFill(['accepted_at' => now()])->save();

    $url = URL::temporarySignedRoute('invitation.accept', $invitation->expires_at, [
        'invitation' => $invitation->id,
        'token' => 'tok',
    ]);

    get($url)->assertForbidden();
});

it('rejects a token mismatch through the signed route', function () {
    $invitation = Invitation::create([
        'email' => 'mismatch@example.com',
        'token' => 'real-token',
        'invited_by' => User::factory()->create()->id,
        'project_ids' => [],
        'expires_at' => now()->addDay(),
    ]);

    $url = URL::temporarySignedRoute('invitation.accept', $invitation->expires_at, [
        'invitation' => $invitation->id,
        'token' => 'wrong-token',
    ]);

    get($url)->assertForbidden();
});

it('rejects a tampered signature', function () {
    $invitation = Invitation::create([
        'email' => 'tamper@example.com',
        'token' => 'tok',
        'invited_by' => User::factory()->create()->id,
        'project_ids' => [],
        'expires_at' => now()->addDay(),
    ]);

    $url = URL::temporarySignedRoute('invitation.accept', $invitation->expires_at, [
        'invitation' => $invitation->id,
        'token' => 'tok',
    ]);

    get($url.'tampered')->assertForbidden();
});
