<?php

namespace App\Models;

use App\Concerns\HasAttachments;
use App\Concerns\HasComments;
use App\Concerns\HasScopedNumber;
use App\Concerns\HasTags;
use App\Concerns\LogsActivity;
use App\Concerns\SanitizesRichText;
use Database\Factories\DocFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A project-scoped reference page (KAN-438): statusless knowledge — specs, lore,
 * system notes — with a canonical home instead of being smeared across task
 * descriptions. Unlike a personal {@see Note}, a doc always belongs to a project
 * and inherits its access; it reuses the platform's rich-text body, tags,
 * comments and attachments, and links bidirectionally with tasks (KAN-439).
 *
 * Referenced by the human-readable "PROJ-D<n>" (project short name + per-project
 * doc number), and grouped into a tree via {@see $parent_id}.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $parent_id
 * @property int $doc_number
 * @property string $title
 * @property string|null $body
 * @property bool $is_public
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $reference
 * @property-read Project $project
 * @property-read Doc|null $parent
 * @property-read Collection<int, Doc> $children
 */
#[Fillable(['title', 'body', 'is_public', 'parent_id'])]
class Doc extends Model
{
    /** @use HasFactory<DocFactory> */
    use HasAttachments, HasComments, HasFactory, HasScopedNumber, HasTags, LogsActivity, SanitizesRichText, SoftDeletes {
        LogsActivity::auditFieldSnapshot as protected baseAuditFieldSnapshot;
    }

    /**
     * How deep a doc tree may nest before a parent assignment is rejected.
     */
    public const int MAX_NESTING_DEPTH = 5;

    protected string $scopedNumberColumn = 'doc_number';

    protected static function booted(): void
    {
        // Validate the parent on create and re-parent (a null parent is a root).
        static::saving(static function (Doc $doc): void {
            if ($doc->parent_id !== null && $doc->isDirty('parent_id')) {
                $doc->assertValidParent();
            }
        });

        // New docs append after their siblings (same project and parent), so the
        // default position order lists them last within their group.
        static::creating(static function (Doc $doc): void {
            if (! array_key_exists('position', $doc->getAttributes())) {
                $doc->position = (int) static::query()
                    ->where('project_id', $doc->project_id)
                    ->where('parent_id', $doc->parent_id)
                    ->max('position') + 1;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * The audited field changes recorded on save. The body is a rich-text field,
     * so — like a task's description — only the fact that it changed is recorded,
     * never its content (a PII liability in an immutable trail).
     *
     * @return array<string, string>
     */
    protected function auditedFieldChanges(): array
    {
        return [
            'title' => 'title_changed',
            'body' => 'body_changed',
            'is_public' => 'visibility_changed',
            'parent_id' => 'parent_changed',
        ];
    }

    /**
     * Keep the rich-text body out of the audit trail (record "it changed" only),
     * mirroring how a task's description is handled.
     */
    protected function auditFieldSnapshot(string $field, mixed $value): ?string
    {
        return $field === 'body' ? null : $this->baseAuditFieldSnapshot($field, $value);
    }

    /**
     * The human-readable reference, e.g. "PROJ-D3": the project short name, a
     * "-D" infix (distinguishing it from a task's "PROJ-3") and the doc number.
     *
     * @return Attribute<non-falsy-string, never>
     */
    protected function reference(): Attribute
    {
        return Attribute::get(fn (): string => $this->project->short_name.'-D'.$this->doc_number);
    }

    /**
     * The query scoping the per-project doc number sequence.
     *
     * @return Builder<static>
     */
    public function scopedNumberQuery(): Builder
    {
        return static::query()->where('project_id', $this->project_id);
    }

    /**
     * The owning project.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The doc this one is nested under, if any.
     *
     * @return BelongsTo<Doc, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * The docs nested directly under this one.
     *
     * @return HasMany<Doc, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    /**
     * Ensure the pending parent is a real doc in the same project that does not
     * point at this doc, close a cycle, or push the chain past the depth limit.
     *
     * @throws InvalidArgumentException
     */
    protected function assertValidParent(): void
    {
        if ($this->parent_id === $this->getKey()) {
            throw new InvalidArgumentException('A doc cannot be its own parent.');
        }

        $parent = static::query()->find($this->parent_id);

        if ($parent === null) {
            throw new InvalidArgumentException('The parent doc does not exist.');
        }

        if ($parent->project_id !== $this->project_id) {
            throw new InvalidArgumentException('A doc can only be nested under a doc in the same project.');
        }

        // Walk from the parent to the root: reaching this doc would close a cycle,
        // and the chain length bounds the nesting depth.
        $depth = 1;

        for ($ancestor = $parent; $ancestor !== null; $ancestor = $ancestor->parent) {
            if ($ancestor->getKey() === $this->getKey()) {
                throw new InvalidArgumentException('A doc cannot be nested under its own descendant.');
            }

            if (++$depth > self::MAX_NESTING_DEPTH) {
                throw new InvalidArgumentException('A doc cannot be nested deeper than '.self::MAX_NESTING_DEPTH.' levels.');
            }
        }
    }
}
