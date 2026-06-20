<?php

namespace App\Concerns;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

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
     * Sync tags from a comma-separated string (or array of names),
     * creating any tags that don't exist yet.
     *
     * @param  string|array<int, string>  $tags
     * @return array{attached: array<int, mixed>, detached: array<int, mixed>, updated: array<int, mixed>}
     */
    public function syncTags(string|array $tags): array
    {
        $ids = collect(is_array($tags) ? $tags : explode(',', $tags))
            ->map(static fn (string $name) => trim($name))
            ->filter()
            ->unique(static fn (string $name) => mb_strtolower($name))
            ->map(static fn (string $name) => Tag::firstOrCreate(['name' => $name])->getKey())
            ->all();

        return $this->tags()->sync($ids);
    }
}
