<?php

use App\Livewire\Settings\Appearance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('persists the full-width preference from the appearance toggle', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Appearance::class)
        ->set('fullWidth', true)
        ->assertRedirect(route('appearance.edit'));

    expect($user->fresh()->preference('full_width'))->toBeTrue();
});

it('turns the full-width preference back off', function () {
    $user = User::factory()->create();
    $user->setPreference('full_width', true);

    Livewire::actingAs($user)
        ->test(Appearance::class)
        ->set('fullWidth', false);

    expect($user->fresh()->preference('full_width'))->toBeFalse();
});

it('seeds the toggle from the saved preference on mount', function () {
    $user = User::factory()->create();
    $user->setPreference('full_width', true);

    Livewire::actingAs($user)
        ->test(Appearance::class)
        ->assertSet('fullWidth', true);
});

it('marks the document full-width when the preference is on', function () {
    $user = User::factory()->create();
    $user->setPreference('full_width', true);

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('full-width', escape: false);
});

it('does not mark the document full-width by default', function () {
    $user = User::factory()->create();

    actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('full-width', escape: false);
});

it('marks capped page containers so full-width can un-cap them', function () {
    $user = User::factory()->create();

    // The notifications page is one of the centered, capped reading columns; it
    // carries the app-content marker the full-width override targets.
    actingAs($user)->get(route('notifications.index'))
        ->assertOk()
        ->assertSee('app-content', escape: false);
});
