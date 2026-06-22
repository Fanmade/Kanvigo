<?php

namespace App\Support;

use App\Models\Project;
use App\Models\Task;

/**
 * Reads inline-image attachment references out of rich-text HTML. Inline images
 * embed the project-scoped attachment routes, whose URLs all contain the segment
 * "attachments/{id}/" (thumbnail, view or download), so a single pattern finds
 * every referenced attachment id regardless of which route variant was embedded.
 */
class InlineAttachments
{
    /**
     * The attachment ids referenced by a single rich-text HTML document.
     *
     * @return array<int, int>
     */
    public static function referencedIds(?string $html): array
    {
        if ($html === null || $html === '') {
            return [];
        }

        preg_match_all('#attachments/(\d+)/#', $html, $matches);

        return array_values(array_unique(array_map(intval(...), $matches[1])));
    }

    /**
     * Every attachment id referenced anywhere in an owner's documents: its own
     * description plus the body of each of its comments.
     *
     * @return array<int, int>
     */
    public static function referencedIdsForOwner(Project|Task $owner): array
    {
        $ids = self::referencedIds($owner->description);

        foreach ($owner->comments()->pluck('body') as $body) {
            $ids = array_merge($ids, self::referencedIds($body));
        }

        return array_values(array_unique($ids));
    }
}
