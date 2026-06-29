<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AvatarController extends Controller
{
    /**
     * Stream a user's avatar image. Avatars live on a private disk and are never
     * exposed through a public URL; viewing is restricted to the profile-page
     * boundary (self, shared-project members, access-all-projects) plus user
     * administrators, so a likeness isn't readable by any authenticated user.
     */
    public function __invoke(User $user): StreamedResponse
    {
        Gate::authorize('viewAvatar', $user);

        abort_unless($user->hasAvatar(), 404);

        $disk = Storage::disk(User::AVATAR_DISK);

        abort_unless($disk->exists($user->avatar_path), 404);

        return $disk->response($user->avatar_path, headers: [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
