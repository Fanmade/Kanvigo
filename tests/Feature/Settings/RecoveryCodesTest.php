<?php

use App\Livewire\Settings\TwoFactor\RecoveryCodes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);
});

/**
 * Put the user into a fully-enabled 2FA state with the given recovery codes.
 *
 * @param  list<string>  $codes
 */
function enableTwoFactorWithCodes(User $user, array $codes): void
{
    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode($codes)),
        'two_factor_confirmed_at' => now(),
    ])->save();
}

test('recovery codes are loaded on mount when two factor is enabled', function () {
    $user = User::factory()->create();
    enableTwoFactorWithCodes($user, ['code-one', 'code-two']);

    $this->actingAs($user);

    Livewire::test(RecoveryCodes::class)
        ->assertHasNoErrors()
        ->assertSet('recoveryCodes', ['code-one', 'code-two']);
});

test('no recovery codes are loaded when two factor is not enabled', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(RecoveryCodes::class)
        ->assertSet('recoveryCodes', []);
});

test('an error is surfaced when stored recovery codes cannot be decrypted', function () {
    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => 'not-valid-ciphertext',
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($user);

    Livewire::test(RecoveryCodes::class)
        ->assertHasErrors('recoveryCodes')
        ->assertSet('recoveryCodes', []);
});

test('regenerating recovery codes replaces them with a fresh set', function () {
    $user = User::factory()->create();
    enableTwoFactorWithCodes($user, ['old-one', 'old-two']);

    $this->actingAs($user);

    $component = Livewire::test(RecoveryCodes::class)
        ->assertSet('recoveryCodes', ['old-one', 'old-two'])
        ->call('regenerateRecoveryCodes')
        ->assertHasNoErrors();

    $codes = $component->get('recoveryCodes');

    expect($codes)->toHaveCount(8)
        ->and($codes)->not->toContain('old-one')
        ->and(json_decode(decrypt($user->refresh()->two_factor_recovery_codes), true))
        ->toEqual($codes);
});
