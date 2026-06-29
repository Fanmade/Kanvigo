<?php

namespace App\Models;

use App\Concerns\MatchesNameCaseInsensitively;
use App\Support\IconCatalog;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $color
 * @property string|null $icon
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
// project_id is set by the owning project relationship / findOrCreateForProject,
// not from user input, so it stays out of the mass-assignable allow-list.
#[Fillable(['name', 'color', 'icon'])]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    use MatchesNameCaseInsensitively;

    /**
     * The default tag palette: Flux UI badge color names, fed straight into
     * <flux:badge :color="..."> just like Priority/Status colors.
     *
     * @var list<string>
     */
    public const array PALETTE = [
        'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald',
        'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple',
        'fuchsia', 'pink', 'rose',
    ];

    /**
     * The neutral fallback color, offered alongside the palette in pickers and
     * accepted when a tag/task type is reset to "no color".
     */
    public const string DEFAULT_COLOR = 'zinc';

    /**
     * Auto-assign a palette color to tags created without one, so every tag
     * renders with a sensible color out of the box.
     */
    protected static function booted(): void
    {
        static::creating(static function (Tag $tag): void {
            if (blank($tag->color)) {
                $tag->color = self::colorForName($tag->name);
            }
        });
    }

    /**
     * Deterministically pick a palette color from the tag name, so the same
     * name always maps to the same color across the app.
     */
    public static function colorForName(string $name): string
    {
        return self::PALETTE[abs(crc32(mb_strtolower($name))) % count(self::PALETTE)];
    }

    /**
     * The palette plus the neutral default color, as offered in the color
     * pickers and accepted when editing a tag or task type.
     *
     * @return list<string>
     */
    public static function paletteWithDefault(): array
    {
        return [...self::PALETTE, self::DEFAULT_COLOR];
    }

    /**
     * Validation rules for a tag/task-type color. Editing may reset to the
     * neutral default ($allowDefault); creating must pick a real palette color.
     *
     * @return list<string>
     */
    public static function colorRule(bool $allowDefault = false): array
    {
        $colors = $allowDefault ? self::paletteWithDefault() : self::PALETTE;

        return ['required', 'string', 'in:'.implode(',', $colors)];
    }

    /**
     * Validation rules for an optional tag/task-type icon from the curated set.
     *
     * @return list<string>
     */
    public static function iconRule(): array
    {
        return ['nullable', 'string', 'in:'.implode(',', IconCatalog::available())];
    }

    /**
     * The single, shared way to resolve a tag within a project. Lookup is
     * case-insensitive — "Bug" and "bug" resolve to the same tag — and the
     * first casing created wins; a brand-new tag keeps the casing it was typed
     * with and gets the given color (or a name-derived default). Every entry
     * point (create dialog, task rail, MCP tools) goes through here so they all
     * dedupe identically.
     */
    public static function findOrCreateForProject(int $projectId, string $name, ?string $color = null, ?string $icon = null): self
    {
        $name = trim($name);

        $existing = self::query()
            ->where('project_id', $projectId)
            ->whereNameLower($name)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $tag = new self(['name' => $name]);
        $tag->project_id = $projectId;

        if ($color !== null) {
            $tag->color = $color;
        }

        $tag->icon = $icon;

        $tag->save();

        return $tag;
    }

    /**
     * The project this tag belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return MorphToMany<Task, $this>
     */
    public function tasks(): MorphToMany
    {
        return $this->morphedByMany(Task::class, 'taggable');
    }

    /**
     * Alternative names this tag is also found by when searching.
     *
     * @return HasMany<TagSynonym, $this>
     */
    public function synonyms(): HasMany
    {
        return $this->hasMany(TagSynonym::class);
    }

    /**
     * Replace this tag's synonyms with the given names. Names are trimmed and
     * de-duplicated case-insensitively; blanks and any name matching the tag's
     * own name are dropped (a tag is already found by its own name). Existing
     * synonyms are kept (not recreated) so their order/timestamps survive.
     *
     * @param  iterable<int, string>  $names
     */
    public function syncSynonyms(iterable $names): void
    {
        $desired = $this->normalizeSynonymNames($names);
        $existing = $this->synonyms()->get();

        $existing
            ->reject(fn (TagSynonym $synonym): bool => $desired->has(mb_strtolower($synonym->name)))
            ->each(static fn (TagSynonym $synonym) => $synonym->delete());

        $desired
            ->reject(static fn (string $name, string $key): bool => $existing->contains(
                static fn (TagSynonym $synonym): bool => mb_strtolower($synonym->name) === $key
            ))
            ->each(fn (string $name) => $this->synonyms()->create(['name' => $name]));
    }

    /**
     * Append the given names as synonyms, skipping any that already exist (case-
     * insensitively) or match the tag's own name. Used when merging tags so the
     * survivor keeps finding the folded-in tags by their old names.
     *
     * @param  iterable<int, string>  $names
     */
    public function addSynonyms(iterable $names): void
    {
        $taken = $this->synonyms()->pluck('name')
            ->map(static fn (string $name): string => mb_strtolower($name))
            ->push(mb_strtolower($this->name))
            ->flip();

        $this->normalizeSynonymNames($names)
            ->reject(static fn (string $name, string $key): bool => $taken->has($key))
            ->each(fn (string $name) => $this->synonyms()->create(['name' => $name]));
    }

    /**
     * Trim, drop blanks and the tag's own name, and de-duplicate the given names
     * case-insensitively. Keyed by the lower-cased name, valued by the original
     * casing to preserve how each synonym was typed.
     *
     * @param  iterable<int, string>  $names
     * @return Collection<string, non-empty-string>
     */
    protected function normalizeSynonymNames(iterable $names): Collection
    {
        $ownName = mb_strtolower($this->name);

        return Collection::make($names)
            ->map(static fn (string $name): string => trim($name))
            ->filter(static fn (string $name): bool => $name !== '')
            ->reject(fn (string $name): bool => mb_strtolower($name) === $ownName)
            ->unique(static fn (string $name): string => mb_strtolower($name))
            ->keyBy(static fn (string $name): string => mb_strtolower($name));
    }

    /**
     * Rename the tag, logging the change against its project. Returns false (and
     * does nothing) when the name is blank or unchanged.
     */
    public function rename(string $newName): bool
    {
        $newName = trim($newName);

        if ($newName === '' || $newName === $this->name) {
            return false;
        }

        $oldName = $this->name;
        $this->update(['name' => $newName]);
        $this->project->recordActivity('tag_renamed', 'tags', $oldName, $newName);

        return true;
    }

    /**
     * Change the tag's color, logging the change against its project. Returns
     * false (and does nothing) when the color is unchanged.
     */
    public function recolor(string $newColor): bool
    {
        if ($newColor === $this->color) {
            return false;
        }

        $oldColor = $this->color;
        $this->update(['color' => $newColor]);
        $this->project->recordActivity(
            'tag_recolored',
            'tags',
            Activity::encodeValue(['name' => $this->name, 'color' => $oldColor]),
            Activity::encodeValue(['name' => $this->name, 'color' => $newColor]),
        );

        return true;
    }

    /**
     * Delete the tag, first logging the deletion against its project so the
     * entry survives (the tag — and its activities — would otherwise be gone).
     */
    public function deleteWithActivity(): void
    {
        $this->project->recordActivity('tag_deleted', 'tags', $this->name, null);
        $this->delete();
    }

    /**
     * Fold this tag into another of the same project: re-point every task tagged
     * with this one onto the target (without creating duplicate pivot rows), then
     * delete this tag, logging the merge against the project. Used both when a
     * rename would collide with an existing tag and by the explicit merge-tags
     * action — the two are merged rather than one silently lost.
     *
     * When $adoptAsSynonym is true the target also takes on this tag's name and
     * its synonyms (skipping duplicates), so it keeps surfacing for searches that
     * used the folded-in name.
     */
    public function mergeInto(self $target, bool $adoptAsSynonym = false): void
    {
        $taskIds = $this->tasks()->pluck('tasks.id')->all();
        $target->tasks()->syncWithoutDetaching($taskIds);

        if ($adoptAsSynonym) {
            $target->addSynonyms($this->synonyms->pluck('name')->push($this->name));
        }

        $this->project->recordActivity('tag_merged', 'tags', $this->name, $target->name);
        $this->delete();
    }
}
