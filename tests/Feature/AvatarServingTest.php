<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Store a real PNG on the avatar disk and return the owning user.
 */
function userWithStoredAvatar(): User
{
    Storage::fake(User::AVATAR_DISK);

    ob_start();
    imagepng(imagecreatetruecolor(10, 10));
    Storage::disk(User::AVATAR_DISK)->put('avatars/example.png', (string) ob_get_clean());

    return User::factory()->create(['avatar_path' => 'avatars/example.png']);
}

test('guests cannot fetch an avatar', function () {
    $user = User::factory()->create(['avatar_path' => 'avatars/example.png']);

    $this->get(route('avatar', $user))->assertRedirect(route('login'));
});

test('a user can fetch their own avatar', function () {
    $owner = userWithStoredAvatar();

    $this->actingAs($owner)
        ->get(route('avatar', $owner))
        ->assertOk()
        ->assertHeader('content-type', 'image/png');
});

test('a member sharing a project can fetch the avatar', function () {
    $owner = userWithStoredAvatar();
    $viewer = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, [$owner, $viewer]);

    $this->actingAs($viewer)
        ->get(route('avatar', $owner))
        ->assertOk()
        ->assertHeader('content-type', 'image/png');
});

test('a user who shares no project cannot fetch the avatar', function () {
    $owner = userWithStoredAvatar();

    $this->actingAs(User::factory()->create())
        ->get(route('avatar', $owner))
        ->assertForbidden();
});

test('a user administrator can fetch any avatar', function () {
    $owner = userWithStoredAvatar();

    $this->actingAs(User::factory()->canManageUsers()->create())
        ->get(route('avatar', $owner))
        ->assertOk()
        ->assertHeader('content-type', 'image/png');
});

test('fetching the avatar of a viewable user without one returns 404', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, [$owner, $viewer]);

    $this->actingAs($viewer)
        ->get(route('avatar', $owner))
        ->assertNotFound();
});
