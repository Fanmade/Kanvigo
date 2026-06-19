<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('avatarUrl is null and hasAvatar is false without an uploaded avatar', function () {
    $user = User::factory()->create();

    expect($user->hasAvatar())->toBeFalse()
        ->and($user->avatarUrl())->toBeNull();
});

test('avatarUrl points at the authorized avatar route for a stored avatar', function () {
    $user = User::factory()->create(['avatar_path' => 'avatars/example.png']);

    expect($user->hasAvatar())->toBeTrue()
        ->and($user->avatarUrl())->toStartWith(route('avatar', $user));
});

test('force-deleting a user removes their avatar file', function () {
    Storage::fake(User::AVATAR_DISK);
    Storage::disk(User::AVATAR_DISK)->put('avatars/gone.png', 'data');

    $user = User::factory()->create(['avatar_path' => 'avatars/gone.png']);

    $user->forceDelete();

    Storage::disk(User::AVATAR_DISK)->assertMissing('avatars/gone.png');
});
