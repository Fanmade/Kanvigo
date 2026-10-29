<?php

use App\Livewire\Settings\Passkeys;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Passkey;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::passkeys());

    Features::passkeys([
        'confirmPassword' => true,
    ]);
});

function createPasskey(User $user, string $name = 'My passkey'): Passkey
{
    return $user->passkeys()->create([
        'name' => $name,
        'credential_id' => 'cred-'.$name,
        'credential' => ['publicKey' => 'test'],
    ]);
}

test('passkeys are loaded on mount', function () {
    $user = User::factory()->create();
    createPasskey($user, 'Phone');

    $this->actingAs($user);

    Livewire::test(Passkeys::class)
        ->assertCount('passkeys', 1)
        ->assertSee('Phone');
});

test('confirming delete opens the modal with the passkey details', function () {
    $user = User::factory()->create();
    $passkey = createPasskey($user, 'Phone');

    $this->actingAs($user);

    Livewire::test(Passkeys::class)
        ->call('confirmDelete', $passkey->id)
        ->assertSet('showDeleteModal', true)
        ->assertSet('deletingPasskeyId', $passkey->id)
        ->assertSet('deletingPasskeyName', 'Phone');
});

test('a passkey can be deleted', function () {
    $user = User::factory()->create();
    $passkey = createPasskey($user, 'Phone');

    $this->actingAs($user);

    Livewire::test(Passkeys::class)
        ->call('confirmDelete', $passkey->id)
        ->call('deletePasskey')
        ->assertSet('showDeleteModal', false)
        ->assertCount('passkeys', 0);

    $this->assertDatabaseMissing('passkeys', ['id' => $passkey->id]);
});

test('closing the delete modal resets its state', function () {
    $user = User::factory()->create();
    $passkey = createPasskey($user, 'Phone');

    $this->actingAs($user);

    Livewire::test(Passkeys::class)
        ->call('confirmDelete', $passkey->id)
        ->call('closeDeleteModal')
        ->assertSet('showDeleteModal', false)
        ->assertSet('deletingPasskeyId', null)
        ->assertSet('deletingPasskeyName', '');
});

test('a user cannot delete another users passkey', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $otherPasskey = createPasskey($other, 'Other');

    $this->actingAs($user);

    Livewire::test(Passkeys::class)->call('confirmDelete', $otherPasskey->id)
        ->assertNotFound();

    $this->assertDatabaseHas('passkeys', ['id' => $otherPasskey->id]);
});
