<?php

namespace App\Concerns;

use App\Models\Keyword;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasKeywords
{
    /**
     * @return MorphToMany<Keyword, $this>
     */
    public function keywords(): MorphToMany
    {
        return $this->morphToMany(Keyword::class, 'keywordable');
    }

    /**
     * Sync keywords from a comma-separated string (or array of names),
     * creating any keywords that don't exist yet.
     *
     * @param  string|array<int, string>  $keywords
     * @return array{attached: array<int, mixed>, detached: array<int, mixed>, updated: array<int, mixed>}
     */
    public function syncKeywords(string|array $keywords): array
    {
        $ids = collect(is_array($keywords) ? $keywords : explode(',', $keywords))
            ->map(static fn (string $name) => trim($name))
            ->filter()
            ->unique(static fn (string $name) => mb_strtolower($name))
            ->map(static fn (string $name) => Keyword::firstOrCreate(['name' => $name])->getKey())
            ->all();

        return $this->keywords()->sync($ids);
    }

    /**
     * The attached keyword names as a comma-separated string.
     */
    public function keywordList(): string
    {
        return $this->keywords->pluck('name')->implode(', ');
    }
}
