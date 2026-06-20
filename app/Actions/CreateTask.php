<?php

namespace App\Actions;

use App\Concerns\Nestable;
use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Story;
use App\Models\Task;

/**
 * The single source of truth for task creation, shared by the board, the story
 * page and the MCP tool. A null priority is left unset so the model inherits the
 * story's priority; a null status defaults to Planned. Passing a $parent nests the
 * new task under it — the {@see Nestable} guard enforces the depth
 * limit and rejects cycles on save.
 */
class CreateTask
{
    public function handle(
        Story $story,
        string $title,
        ?string $description = null,
        ?Priority $priority = null,
        ?Status $status = null,
        ?string $dueDate = null,
        ?Task $parent = null,
    ): Task {
        $task = $story->tasks()->make([
            'title' => $title,
            'description' => $description,
            'due_date' => $dueDate ?: null,
        ]);

        if ($priority !== null) {
            $task->priority = $priority;
        }

        if ($parent !== null) {
            $task->parent_id = $parent->getKey();
        }

        $task->status = $status ?? Status::Planned;
        $task->save();

        return $task;
    }
}
