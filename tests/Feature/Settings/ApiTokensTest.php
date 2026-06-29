<?php

use App\Livewire\Settings\ApiTokens;
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

test('a permitted user can revoke a token', function () {
    $user = User::factory()->canCreateApiTokens()->create();

    $tokenId = $user->createToken('Revoke me', ['read'])->accessToken->id;

    expect($user->tokens()->count())->toBe(1);

    Livewire::actingAs($user)
        ->test(ApiTokens::class)
        ->call('revoke', $tokenId);

    expect($user->tokens()->count())->toBe(0);
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
