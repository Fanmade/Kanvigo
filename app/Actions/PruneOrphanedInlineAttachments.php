<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\Task;
use App\Support\InlineAttachments;

/**
 * Removes an owner's inline-image attachments that are no longer referenced by
 * any of its rich-text documents (its description and every comment body),
 * deleting the stored file and thumbnail with each row.
 */
class PruneOrphanedInlineAttachments
{
    /**
     * Delete the owner's now-orphaned inline attachments.
     *
     * When $candidateIds is given, only those attachments are considered — the
     * edit/delete triggers pass the ids a document *used to* reference, so a
     * freshly-uploaded image not yet saved into any document is never deleted
     * here (the daily sweep reclaims those instead). Passing null considers every
     * inline attachment of the owner.
     *
     * @param  array<int, int>|null  $candidateIds
     */
    public function forOwner(Project|Task $owner, ?array $candidateIds = null): int
    {
        if ($candidateIds === []) {
            return 0;
        }

        $query = $owner->attachments()->where('is_inline', true);

        if ($candidateIds !== null) {
            $query->whereIn('id', $candidateIds);
        }

        $candidates = $query->get();

        if ($candidates->isEmpty()) {
            return 0;
        }

        $referenced = InlineAttachments::referencedIdsForOwner($owner);
        $deleted = 0;

        foreach ($candidates as $attachment) {
            if (in_array($attachment->id, $referenced, true)) {
                continue;
            }

            // Per-model delete fires the Attachment "deleting" hook that removes
            // the stored file and thumbnail.
            $attachment->delete();
            $deleted++;
        }

        return $deleted;
    }
}
