<?php

namespace App\Mcp\Concerns;

use App\Models\Story;
use App\Models\Task;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Serializes a story or task's dependency links for the MCP read tools: the
 * references of the items blocking it, the items it blocks, and whether any
 * blocker is still unfinished.
 */
trait ExposesDependencies
{
    /**
     * Build the dependency payload for a story or task, eager-loading the linked
     * items (and the relations needed to compute their references and
     * completeness) in one pass to avoid N+1 queries.
     *
     * @return array{blocked_by: array<int, string>, blocks: array<int, string>, is_blocked: bool}
     */
    protected function dependencyPayload(Story|Task $item): array
    {
        $item->loadMissing([
            'dependencyLinks.blocker' => static fn (MorphTo $morphTo) => $morphTo->morphWith([
                Story::class => ['project', 'tasks'],
                Task::class => ['story.project'],
            ]),
            'dependentLinks.dependent' => static fn (MorphTo $morphTo) => $morphTo->morphWith([
                Story::class => ['project'],
                Task::class => ['story.project'],
            ]),
        ]);

        return [
            'blocked_by' => $item->blockers()->map(static fn (Story|Task $blocker): string => $blocker->reference)->values()->all(),
            'blocks' => $item->blocking()->map(static fn (Story|Task $blocked): string => $blocked->reference)->values()->all(),
            'is_blocked' => $item->isBlocked(),
        ];
    }
}
