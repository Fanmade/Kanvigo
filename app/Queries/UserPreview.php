<?php

namespace App\Queries;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The compact preview of a user shown in the @mention hovercard: enough to know
 * who a mention points at — their name, avatar and, when the mention carries the
 * project it lives in, their role(s) in that project — without opening the
 * profile.
 */
class UserPreview
{
    /**
     * @return array{name: string, avatar_url: string|null, initials: string, roles: list<string>}
     */
    public function handle(User $user, ?Project $project = null): array
    {
        return [
            'name' => $user->name,
            'avatar_url' => $user->avatarUrl(),
            'initials' => $user->initials(),
            // Role labels are localized the same way the member panel does it
            // (Str::headline), and only shown when the mention's project is known
            // and visible to the reader.
            'roles' => $project === null
                ? []
                : array_map(static fn (string $name): string => Str::headline($name), $project->roleNamesFor($user)),
        ];
    }
}
