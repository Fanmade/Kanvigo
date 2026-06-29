<?php

namespace App\Models;

use App\Concerns\MatchesNameCaseInsensitively;
use Database\Factories\TaskTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A per-project, user-configurable classification for tasks (e.g. Feature, Bug,
 * Chore). Each type carries a Flux badge {@see $color} and a Heroicon
 * {@see $icon} for display, and an optional {@see $branch_prefix} that drives the
 * git branch name for tasks of this type (see KAN-257). The set of types is
 * scoped to a project; new projects are seeded with {@see DEFAULTS}.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $color
 * @property string|null $icon
 * @property string|null $branch_prefix
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Collection<int, Task> $tasks
 */
#[Fillable(['name', 'color', 'icon', 'branch_prefix', 'position'])]
class TaskType extends Model
{
    /** @use HasFactory<TaskTypeFactory> */
    use HasFactory;

    use MatchesNameCaseInsensitively;

    /**
     * The default types seeded into every new project — a sensible starting set
     * users can rename, recolor or extend. Order here is the seeded position.
     *
     * @var list<array{name: string, icon: string, color: string, branch_prefix: string}>
     */
    public const array DEFAULTS = [
        ['name' => 'Feature', 'icon' => 'sparkles', 'color' => 'sky', 'branch_prefix' => 'feat'],
        ['name' => 'Bug', 'icon' => 'bug-ant', 'color' => 'red', 'branch_prefix' => 'bugfix'],
        ['name' => 'Chore', 'icon' => 'wrench-screwdriver', 'color' => 'zinc', 'branch_prefix' => 'chore'],
    ];

    /**
     * Seed the {@see DEFAULTS} into a project. Idempotent: a type whose name
     * already exists in the project (case-insensitively) is left untouched, so
     * this is safe to call more than once.
     */
    public static function provisionDefaults(Project $project): void
    {
        foreach (self::DEFAULTS as $position => $default) {
            $exists = $project->taskTypes()
                ->whereNameLower($default['name'])
                ->exists();

            if (! $exists) {
                $project->taskTypes()->create([...$default, 'position' => $position]);
            }
        }
    }

    /**
     * The project this type belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The tasks classified with this type.
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
