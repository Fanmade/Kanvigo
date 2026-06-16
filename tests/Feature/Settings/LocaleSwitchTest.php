<?php

use App\Livewire\Settings\Appearance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('uses the browser preferred language by default', function () {
    $user = User::factory()->create();

    actingAs($user)->get('/dashboard', ['Accept-Language' => 'de'])->assertOk();

    expect(app()->getLocale())->toBe('de');
});

it('prefers the session locale over the browser header', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->withSession(['locale' => 'en'])
        ->get('/dashboard', ['Accept-Language' => 'de'])
        ->assertOk();

    expect(app()->getLocale())->toBe('en');
});

it('persists a chosen locale to the session from the appearance switch', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Appearance::class)
        ->set('locale', 'de')
        ->assertRedirect(route('appearance.edit'));

    expect(session('locale'))->toBe('de');
});
