<?php

use App\Livewire\Settings\ApiTokens;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('a permitted user can create a read-only token', function () {
    $user = User::factory()->canCreateApiTokens()->create();

    Livewire::actingAs($user)
        ->test(ApiTokens::class)
        ->set('name', 'My token')
        ->set('accessLevel', 'read')
        ->call('createToken')
        ->assertHasNoErrors()
        ->assertSet('plainTextToken', fn ($token) => is_string($token) && $token !== '');

    $token = $user->tokens()->firstOrFail();

    expect($token->name)->toBe('My token');
    expect($token->abilities)->toBe(['read']);
});

test('a permitted user can create a read and write token', function () {
    $user = User::factory()->canCreateApiTokens()->create();

    Livewire::actingAs($user)
        ->test(ApiTokens::class)
        ->set('name', 'Write token')
        ->set('accessLevel', 'write')
        ->call('createToken')
        ->assertHasNoErrors();

    expect($user->tokens()->firstOrFail()->abilities)->toBe(['read', 'write']);
});

test('an operator with manage-users can create an audit-stream token', function () {
    $operator = User::factory()->canCreateApiTokens()->canManageUsers()->create();

    Livewire::actingAs($operator)
        ->test(ApiTokens::class)
        ->assertSet('canMintAuditTokens', true)
        ->assertSeeHtml('token-access-audit')
        ->set('name', 'SIEM feed')
        ->set('accessLevel', 'audit')
        ->call('createToken')
        ->assertHasNoErrors();

    $token = $operator->tokens()->firstOrFail();

    expect($token->abilities)->toBe(['audit']);
    // The audit scope is instance-wide, never project-restricted.
    expect($token->restrictsProjects())->toBeFalse();
});

test('an audit token ignores a selected project scope', function () {
    $operator = User::factory()->canCreateApiTokens()->canManageUsers()->create();
    $project = Project::factory()->withMembers([$operator])->create();

    Livewire::actingAs($operator)
        ->test(ApiTokens::class)
        ->set('name', 'SIEM feed')
        ->set('accessLevel', 'audit')
        ->set('projectScope', 'selected')
        ->set('selectedProjects', [(string) $project->id])
        ->call('createToken')
        ->assertHasNoErrors();

    $token = $operator->tokens()->firstOrFail();

    expect($token->abilities)->toBe(['audit']);
    expect($token->restrictsProjects())->toBeFalse();
    expect($token->projects()->count())->toBe(0);
});

test('a user without manage-users cannot create an audit token', function () {
    $user = User::factory()->canCreateApiTokens()->create();

    Livewire::actingAs($user)
        ->test(ApiTokens::class)
        ->assertSet('canMintAuditTokens', false)
        ->assertDontSeeHtml('token-access-audit')
        ->set('name', 'Sneaky')
        ->set('accessLevel', 'audit')
        ->call('createToken')
        ->assertHasErrors('accessLevel');

    expect($user->tokens()->count())->toBe(0);
});

test('the token list labels an audit token', function () {
    $operator = User::factory()->canCreateApiTokens()->canManageUsers()->create();

    $audit = $operator->createToken('SIEM feed', ['audit'])->accessToken;

    $tokens = collect(Livewire::actingAs($operator)->test(ApiTokens::class)->instance()->tokens())->keyBy('id');

    expect($tokens[$audit->id]['abilities_label'])->toBe(__('Audit event stream'));
});

test('a permitted user can revoke a token', function () {
    $user = User::factory()->canCreateApiTokens()->create();

    $tokenId = $user->createToken('Revoke me', ['read'])->accessToken->id;

    expect($user->tokens()->count())->toBe(1);

    Livewire::actingAs($user)
        ->test(ApiTokens::class)
        ->call('revoke', $tokenId);

    expect($user->tokens()->count())->toBe(0);
});

test('a token is unrestricted by default', function () {
    $user = User::factory()->canCreateApiTokens()->create();

    Livewire::actingAs($user)
        ->test(ApiTokens::class)
        ->set('name', 'Unrestricted')
        ->call('createToken')
        ->assertHasNoErrors();

    $token = $user->tokens()->firstOrFail();

    expect($token->restrictsProjects())->toBeFalse();
    expect($token->projects()->count())->toBe(0);
});

test('a token can be restricted to selected projects', function () {
    $user = User::factory()->canCreateApiTokens()->create();
    $allowed = Project::factory()->withMembers([$user])->create();
    Project::factory()->withMembers([$user])->create();

    Livewire::actingAs($user)
        ->test(ApiTokens::class)
        ->set('name', 'Scoped')
        ->set('projectScope', 'selected')
        ->set('selectedProjects', [(string) $allowed->id])
        ->call('createToken')
        ->assertHasNoErrors();

    $token = $user->tokens()->firstOrFail();

    expect($token->restrictsProjects())->toBeTrue();
    expect($token->projects()->pluck('projects.id')->all())->toBe([$allowed->id]);
});

test('a restricted token requires at least one selected project', function () {
    $user = User::factory()->canCreateApiTokens()->create();
    Project::factory()->withMembers([$user])->create();

    Livewire::actingAs($user)
        ->test(ApiTokens::class)
        ->set('name', 'Scoped')
        ->set('projectScope', 'selected')
        ->set('selectedProjects', [])
        ->call('createToken')
        ->assertHasErrors('selectedProjects');

    expect($user->tokens()->count())->toBe(0);
});

test('a token cannot be restricted to a project the user is not a member of', function () {
    $user = User::factory()->canCreateApiTokens()->create();
    Project::factory()->withMembers([$user])->create();
    $foreign = Project::factory()->create();

    Livewire::actingAs($user)
        ->test(ApiTokens::class)
        ->set('name', 'Scoped')
        ->set('projectScope', 'selected')
        ->set('selectedProjects', [(string) $foreign->id])
        ->call('createToken')
        ->assertHasErrors('selectedProjects');

    expect($user->tokens()->count())->toBe(0);
});

test('the token list labels the project scope', function () {
    $user = User::factory()->canCreateApiTokens()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'SCP']);

    $unrestricted = $user->createToken('Legacy', ['read'])->accessToken;
    $restricted = $user->createToken('Scoped', ['read'])->accessToken;
    $restricted->forceFill(['restricts_projects' => true])->save();
    $restricted->projects()->attach($project->id);

    $tokens = collect(Livewire::actingAs($user)->test(ApiTokens::class)->instance()->tokens())->keyBy('id');

    expect($tokens[$unrestricted->id]['projects_label'])->toBe(__('All projects'));
    expect($tokens[$restricted->id]['projects_label'])->toBe('SCP');
});

test('a user without permission is forbidden', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ApiTokens::class)
        ->assertForbidden();
});

test('the api tokens page requires a recent password confirmation', function () {
    $user = User::factory()->canCreateApiTokens()->create();

    $this->actingAs($user)
        ->get(route('api-tokens.edit'))
        ->assertRedirect(route('password.confirm'));
});

test('the api tokens page renders once the password is confirmed', function () {
    $user = User::factory()->canCreateApiTokens()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('api-tokens.edit'))
        ->assertOk();
});

test('the token name is required', function () {
    $user = User::factory()->canCreateApiTokens()->create();

    Livewire::actingAs($user)
        ->test(ApiTokens::class)
        ->set('name', '')
        ->set('accessLevel', 'read')
        ->call('createToken')
        ->assertHasErrors(['name' => 'required']);

    expect($user->tokens()->count())->toBe(0);
});
