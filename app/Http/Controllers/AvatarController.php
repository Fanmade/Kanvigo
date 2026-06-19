<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AvatarController extends Controller
{
    /**
     * Stream a user's avatar image to authenticated viewers. Avatars live on a
     * private disk and are never exposed through a public URL, so an account's
     * existence and likeness cannot be discovered by scanning storage.
     */
    public function __invoke(User $user): StreamedResponse
    {
        abort_unless($user->hasAvatar(), 404);

        $disk = Storage::disk(User::AVATAR_DISK);

        abort_unless($disk->exists($user->avatar_path), 404);

        return $disk->response($user->avatar_path, headers: [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
