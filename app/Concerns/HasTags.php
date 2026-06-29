<?php

namespace App\Concerns;

use App\Models\Activity;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Tags are project-scoped, so the using model must expose the owning project and
 * the activity-log helper used by {@see recordTagSync()}.
 *
 * @property int $project_id
 *
 * @method Activity|null recordTagChange(array<int, string> $addedNames, array<int, string> $removedNames)
 */
trait HasTags
{
    /**
     * @return MorphToMany<Tag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /**
     * Sync tags from a comma-separated string (or array of names), creating any
     * that don't exist yet in this model's project.
     *
     * @param  string|array<int, string>  $tags
     * @return array{attached: array<int, mixed>, detached: array<int, mixed>, updated: array<int, mixed>}
     */
    public function syncTags(string|array $tags): array
    {
        $projectId = $this->project_id;

        $ids = collect(is_array($tags) ? $tags : explode(',', $tags))
            ->map(static fn (string $name) => trim($name))
            ->filter()
            ->unique(static fn (string $name) => mb_strtolower($name))
            ->map(static fn (string $name) => Tag::findOrCreateForProject($projectId, $name)->getKey())
            ->all();

        return $this->tags()->sync($ids);
    }

    /**
     * Record a tags_changed activity from a {@see syncTags()} diff, resolving the
     * attached/detached tag ids to their names so the trail shows what was added
     * and removed. No-ops when nothing changed. Kept separate from syncTags()
     * because not every sync should be logged (e.g. setting tags on creation).
     *
     * @param  array{attached: array<int, mixed>, detached: array<int, mixed>, updated: array<int, mixed>}  $changes
     */
    public function recordTagSync(array $changes): void
    {
        $names = Tag::query()
            ->whereIn('id', array_merge($changes['attached'], $changes['detached']))
            ->pluck('name', 'id');

        $resolve = static fn (array $ids): array => collect($ids)
            ->map(static fn ($id) => $names[(int) $id] ?? null)
            ->filter()
            ->values()
            ->all();

        $this->recordTagChange($resolve($changes['attached']), $resolve($changes['detached']));
    }
}
