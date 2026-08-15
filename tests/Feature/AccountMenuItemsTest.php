<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('links to the current user\'s own profile', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $view = $this->blade('<x-account-menu-items test-prefix="sidebar-account" />');

    $view->assertSee('sidebar-account-profile')
        ->assertSee(route('users.show', $user), false)
        ->assertSee('View profile');
});

it('offers the theme options, bound to the same store as the appearance page', function () {
    $this->actingAs(User::factory()->create());

    $this->blade('<x-account-menu-items test-prefix="header-account" />')
        ->assertSee('header-account-theme-light')
        ->assertSee('header-account-theme-dark')
        ->assertSee('header-account-theme-system')
        ->assertSee('x-model="$flux.appearance"', false);
});
