<?php

namespace App\Concerns;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Tags are project-scoped, so the using model must expose the owning project.
 *
 * @property int $project_id
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
}
