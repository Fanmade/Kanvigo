<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $color
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'color'])]
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
     * @return MorphToMany<Task, $this>
     */
    public function tasks(): MorphToMany
    {
        return $this->morphedByMany(Task::class, 'taggable');
    }
}
