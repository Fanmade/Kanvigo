<?php

namespace App\Queries;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The most-used tags in a project, offered as suggestions in the tag inputs
 * (the create-task dialog and the task rail). Ranked by usage (most-applied
 * first, then alphabetically) and returned as lightweight `{name, color}` rows.
 *
 * Two exclusion modes serve the two call sites: by id (the rail omits the tags
 * already on the item) and by lower-cased name (the create dialog omits names
 * already staged, which may be brand-new tags with no id yet). An optional
 * search matches the tag name or any of its synonyms.
 */
class TagSuggestions
{
    /**
     * @param  array<int, int>  $excludeIds  tag ids to omit (matched in the query)
     * @param  array<int, string>  $excludeNames  lower-cased names to omit (matched after fetch)
     * @return Collection<int, array{name: string, color: string}>
     */
    public function handle(
        int $projectId,
        ?string $search = null,
        array $excludeIds = [],
        array $excludeNames = [],
        int $limit = 12,
        ?int $take = null,
    ): Collection {
        $rows = Tag::query()
            ->where('tags.project_id', $projectId)
            ->select('tags.id', 'tags.name', 'tags.color')
            ->selectSub(
                DB::table('taggables')
                    ->selectRaw('count(*)')
                    ->whereColumn('taggables.tag_id', 'tags.id'),
                'usage_count'
            )
            ->when($excludeIds !== [], static fn (Builder $query) => $query->whereNotIn('tags.id', $excludeIds))
            ->when(
                $search !== null && $search !== '',
                static fn (Builder $query) => $query->where(static function (Builder $tags) use ($search): void {
                    // Match the tag's own name or any of its synonyms, so typing
                    // "eval" still surfaces the "Research" tag (synonym "Evaluation").
                    $tags->whereLike('tags.name', '%'.$search.'%')
                        ->orWhereHas('synonyms', static fn (Builder $synonyms) => $synonyms->whereLike('name', '%'.$search.'%'));
                })
            )
            ->orderByDesc('usage_count')
            ->orderBy('tags.name')
            ->limit($limit)
            ->get()
            ->reject(static fn (Tag $tag): bool => in_array(mb_strtolower($tag->name), $excludeNames, true));

        return $rows
            ->when($take !== null, static fn (Collection $tags) => $tags->take($take))
            ->map(static fn (Tag $tag): array => ['name' => $tag->name, 'color' => $tag->color])
            ->values();
    }
}
