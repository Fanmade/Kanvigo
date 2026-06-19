<?php

use App\Livewire\Settings\Profile;
use App\Models\User;
use App\Support\Avatar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('a user can upload an avatar that is cropped to a square', function () {
    Storage::fake(User::AVATAR_DISK);
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('avatar', UploadedFile::fake()->image('me.jpg', 800, 400))
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->avatar_path)->not->toBeNull();
    Storage::disk(User::AVATAR_DISK)->assertExists($user->avatar_path);

    [$width, $height] = getimagesizefromstring(Storage::disk(User::AVATAR_DISK)->get($user->avatar_path));
    expect($width)->toBe(Avatar::SIZE)
        ->and($height)->toBe(Avatar::SIZE);
});

test('uploading a new avatar replaces the previous one', function () {
    Storage::fake(User::AVATAR_DISK);
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('avatar', UploadedFile::fake()->image('one.jpg'))
        ->assertHasNoErrors();

    $first = $user->refresh()->avatar_path;

    Livewire::test(Profile::class)
        ->set('avatar', UploadedFile::fake()->image('two.jpg'))
        ->assertHasNoErrors();

    $second = $user->refresh()->avatar_path;

    expect($second)->not->toBe($first);
    Storage::disk(User::AVATAR_DISK)->assertMissing($first);
    Storage::disk(User::AVATAR_DISK)->assertExists($second);
});

test('the avatar upload must be an image', function () {
    Storage::fake(User::AVATAR_DISK);
    $this->actingAs(User::factory()->create());

    Livewire::test(Profile::class)
        ->set('avatar', UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'))
        ->assertHasErrors('avatar');
});

test('the avatar upload rejects images over the size limit', function () {
    Storage::fake(User::AVATAR_DISK);
    $this->actingAs(User::factory()->create());

    Livewire::test(Profile::class)
        ->set('avatar', UploadedFile::fake()->image('huge.jpg')->size(5000))
        ->assertHasErrors('avatar');
});

test('a user can remove their avatar', function () {
    Storage::fake(User::AVATAR_DISK);
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('avatar', UploadedFile::fake()->image('me.jpg'))
        ->assertHasNoErrors();

    $path = $user->refresh()->avatar_path;

    Livewire::test(Profile::class)
        ->call('removeAvatar')
        ->assertHasNoErrors();

    expect($user->refresh()->avatar_path)->toBeNull();
    Storage::disk(User::AVATAR_DISK)->assertMissing($path);
});
