<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $color
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['project_id', 'name', 'color'])]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

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
     * The single, shared way to resolve a tag within a project. Lookup is
     * case-insensitive — "Bug" and "bug" resolve to the same tag — and the
     * first casing created wins; a brand-new tag keeps the casing it was typed
     * with and gets the given color (or a name-derived default). Every entry
     * point (create dialog, task rail, MCP tools) goes through here so they all
     * dedupe identically.
     */
    public static function findOrCreateForProject(int $projectId, string $name, ?string $color = null): self
    {
        $name = trim($name);

        $existing = self::query()
            ->where('project_id', $projectId)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $tag = new self(['project_id' => $projectId, 'name' => $name]);

        if ($color !== null) {
            $tag->color = $color;
        }

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
            json_encode(['name' => $this->name, 'color' => $oldColor], JSON_THROW_ON_ERROR),
            json_encode(['name' => $this->name, 'color' => $newColor], JSON_THROW_ON_ERROR),
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
}
