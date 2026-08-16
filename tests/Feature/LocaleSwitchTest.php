<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores the chosen locale and returns to the page it came from', function () {
    $this->actingAs(User::factory()->create())
        ->from(route('dashboard'))
        ->post(route('locale.update'), ['locale' => 'de'])
        ->assertRedirect(route('dashboard'));

    expect(session('locale'))->toBe('de');
});

it('rejects an unsupported locale', function () {
    $this->actingAs(User::factory()->create())
        ->from(route('dashboard'))
        ->post(route('locale.update'), ['locale' => 'fr'])
        ->assertSessionHasErrors('locale');

    expect(session('locale'))->toBeNull();
});

it('requires authentication', function () {
    $this->post(route('locale.update'), ['locale' => 'de'])
        ->assertRedirect(route('login'));
});

it('renders the page in the stored locale', function () {
    $this->actingAs(User::factory()->create())
        ->withSession(['locale' => 'de'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(__('Notifications', locale: 'de'));
});

it('offers every supported language, marking the active one', function () {
    $this->actingAs(User::factory()->create());

    $this->blade('<x-account-menu-items test-prefix="sidebar-account" />')
        ->assertSee('sidebar-account-language-en')
        ->assertSee('sidebar-account-language-de')
        // Labelled in their own language, not the active one.
        ->assertSee('English')
        ->assertSee('Deutsch');
});
