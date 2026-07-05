<?php

namespace App\Models;

use App\Concerns\HasAttachments;
use App\Concerns\SanitizesRichText;
use App\Support\Facades\Audit;
use Database\Factories\NoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Kanvigo\Audit\Contracts\AuditCategory;
use Kanvigo\Audit\Contracts\AuditEvent;

/**
 * A personal note: the first user-owned, projectless entity. Private to its
 * author by default; may optionally be attached to a project and, separately,
 * made public (read-only) to that project's members.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $project_id
 * @property bool $is_public
 * @property bool $is_pinned
 * @property int $position
 * @property string $title
 * @property string|null $body
 * @property int|null $converted_task_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read Project|null $project
 * @property-read Task|null $convertedTask
 */
#[Fillable(['title', 'body', 'project_id', 'is_public', 'is_pinned'])]
class Note extends Model
{
    /** @use HasFactory<NoteFactory> */
    use HasAttachments, HasFactory, SanitizesRichText, SoftDeletes;

    protected static function booted(): void
    {
        // Invariant: a note can only be public while attached to a project.
        // Saving public without a project (or after clearing it) falls back to
        // private rather than leaving an orphaned-public note.
        static::saving(static function (Note $note): void {
            if ($note->project_id === null) {
                $note->is_public = false;
            }
        });

        // New notes append to the top of their owner's list: the next-highest
        // position, so the default order (position descending) shows them first.
        static::creating(static function (Note $note): void {
            if (! array_key_exists('position', $note->getAttributes())) {
                $note->position = (int) static::query()->where('user_id', $note->user_id)->max('position') + 1;
            }
        });

        // The note lifecycle is audited at the model level so every write path
        // (Livewire, MCP, REST) is covered. Notes are not a feed subject, so
        // these events flow to the outbox and ledger sinks only. Payloads stay
        // reference-shaped: which fields changed, never the note's content.
        static::created(static function (Note $note): void {
            Audit::record($note->auditEvent('note_created')
                ->withMetadata(['project_id' => $note->project_id]));
        });

        static::updated(static function (Note $note): void {
            if ($note->wasChanged('converted_task_id') && $note->converted_task_id !== null) {
                Audit::record($note->auditEvent('note_converted')
                    ->withMetadata(['task_id' => $note->converted_task_id]));
            }

            $fields = array_values(array_diff(
                array_keys($note->getChanges()),
                ['updated_at', 'position', 'converted_task_id', 'deleted_at'],
            ));

            if ($fields !== []) {
                Audit::record($note->auditEvent('note_updated')
                    ->withMetadata(['fields' => $fields]));
            }
        });

        static::deleted(static function (Note $note): void {
            Audit::record($note->auditEvent('note_deleted'));
        });
    }

    /**
     * A content audit event with this note as its subject.
     */
    protected function auditEvent(string $action): AuditEvent
    {
        return AuditEvent::make($action, AuditCategory::Content)
            ->withSubject($this->getMorphClass(), $this->getKey());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'is_pinned' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * The note's owner.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The project the note is attached to, if any.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The task this note was converted into, if any.
     *
     * @return BelongsTo<Task, $this>
     */
    public function convertedTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'converted_task_id');
    }
}
