<?php

namespace App\Concerns;

use App\Actions\PruneOrphanedInlineAttachments;
use App\Models\Project;
use App\Models\Task;
use App\Support\InlineAttachments;
use Illuminate\Database\Eloquent\Model;

/**
 * Prunes an owner's now-orphaned inline-image attachments whenever a rich-text
 * document — a task/project description or a comment body — is edited or removed.
 *
 * Only the attachments the document *previously* referenced are considered, so a
 * freshly-uploaded image not yet saved into any document is left untouched (the
 * daily sweep reclaims those). Because it hooks model events, it covers every
 * edit path — the UI, the MCP tools, anything that saves the model.
 *
 * @phpstan-require-extends Model
 */
trait PrunesInlineAttachments
{
    public static function bootPrunesInlineAttachments(): void
    {
        static::updated(static function (Model $model): void {
            /** @var Model&self $model */
            $column = $model->inlineDocumentColumn();

            if (! $model->wasChanged($column)) {
                return;
            }

            $model->pruneOrphanedInlineAttachments(
                InlineAttachments::referencedIds((string) $model->getOriginal($column))
            );
        });

        static::deleted(static function (Model $model): void {
            /** @var Model&self $model */
            $model->pruneOrphanedInlineAttachments(
                InlineAttachments::referencedIds((string) $model->getAttribute($model->inlineDocumentColumn()))
            );
        });
    }

    /**
     * Prune the owner's inline attachments among $candidateIds that are no longer
     * referenced by any of the owner's documents.
     *
     * @param  array<int, int>  $candidateIds
     */
    protected function pruneOrphanedInlineAttachments(array $candidateIds): void
    {
        if ($candidateIds === []) {
            return;
        }

        $owner = $this->inlineAttachmentOwner();

        // When the owner itself is the thing being deleted, there is nothing left
        // to reconcile against here (whole-owner cleanup is a separate concern).
        if (! $owner->exists) {
            return;
        }

        app(PruneOrphanedInlineAttachments::class)->forOwner($owner, $candidateIds);
    }

    /**
     * The Task or Project that owns the inline attachments for this document.
     */
    abstract public function inlineAttachmentOwner(): Project|Task;

    /**
     * The attribute holding the rich-text HTML document (e.g. "description").
     */
    abstract public function inlineDocumentColumn(): string;
}
