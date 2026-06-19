<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('guests cannot fetch an avatar', function () {
    $user = User::factory()->create(['avatar_path' => 'avatars/example.png']);

    $this->get(route('avatar', $user))->assertRedirect(route('login'));
});

test('an authenticated user can fetch a stored avatar', function () {
    Storage::fake(User::AVATAR_DISK);

    ob_start();
    imagepng(imagecreatetruecolor(10, 10));
    Storage::disk(User::AVATAR_DISK)->put('avatars/example.png', (string) ob_get_clean());

    $owner = User::factory()->create(['avatar_path' => 'avatars/example.png']);

    $this->actingAs(User::factory()->create())
        ->get(route('avatar', $owner))
        ->assertOk()
        ->assertHeader('content-type', 'image/png');
});

test('fetching the avatar of a user without one returns 404', function () {
    $owner = User::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('avatar', $owner))
        ->assertNotFound();
});
