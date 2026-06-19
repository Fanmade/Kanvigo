<?php

namespace App\Actions;

use App\Enums\Priority;
use App\Models\Project;
use App\Models\Story;

/**
 * The single source of truth for story creation, shared by the board, the project
 * page and the MCP tool, so every entry point produces an identical story (same
 * priority and due-date defaults). A null priority falls back to the default.
 */
class CreateStory
{
    public function handle(
        Project $project,
        string $title,
        ?string $description = null,
        ?Priority $priority = null,
        ?string $dueDate = null,
    ): Story {
        return $project->stories()->create([
            'title' => $title,
            'description' => $description,
            'priority' => $priority ?? Priority::default(),
            'due_date' => $dueDate ?: null,
        ]);
    }
}
