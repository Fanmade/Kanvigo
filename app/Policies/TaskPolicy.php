<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    /**
     * Access to a task cascades from access to its story's project.
     */
    public function view(User $user, Task $task): bool
    {
        return $user->can('view', $task->story->project);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }

    /**
     * Determine whether the user can move the task on the board.
     */
    public function updateStatus(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }
}
