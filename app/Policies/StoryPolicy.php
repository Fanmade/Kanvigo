<?php

namespace App\Policies;

use App\Models\Story;
use App\Models\User;

class StoryPolicy
{
    /**
     * Access to a story cascades from access to its project.
     */
    public function view(User $user, Story $story): bool
    {
        return $user->can('view', $story->project);
    }

    public function update(User $user, Story $story): bool
    {
        return $this->view($user, $story);
    }

    public function delete(User $user, Story $story): bool
    {
        return $this->view($user, $story);
    }
}
