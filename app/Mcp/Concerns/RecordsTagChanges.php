<?php

namespace App\Mcp\Concerns;

use App\Concerns\HasTags;
use App\Models\Tag;
use App\Models\Task;

trait RecordsTagChanges
{
    /**
     * Record a tags_changed activity from a {@see HasTags::syncTags()}
     * diff, resolving the attached/detached tag IDs to their names so the trail
     * shows which tags were added and removed. No-ops when nothing changed.
     *
     * @param  array{attached: array<int, mixed>, detached: array<int, mixed>, updated: array<int, mixed>}  $changes
     */
    protected function recordTagSync(Task $item, array $changes): void
    {
        $names = Tag::query()
            ->whereIn('id', array_merge($changes['attached'], $changes['detached']))
            ->pluck('name', 'id');

        $resolve = static fn (array $ids): array => collect($ids)
            ->map(static fn ($id) => $names[(int) $id] ?? null)
            ->filter()
            ->values()
            ->all();

        $item->recordTagChange($resolve($changes['attached']), $resolve($changes['detached']));
    }
}
