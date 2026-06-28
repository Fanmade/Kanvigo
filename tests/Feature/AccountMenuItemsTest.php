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
