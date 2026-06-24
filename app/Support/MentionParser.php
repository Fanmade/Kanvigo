<?php

namespace App\Support;

use Dom\HTMLDocument;

/**
 * Extracts @mention targets from stored rich-text (HTML).
 *
 * Mentions are stored as atomic inline nodes carrying the mentioned user's id,
 * e.g. {@code <span class="mention" data-type="mention" data-id="5">@Name</span>}.
 * Parsing the saved HTML (rather than the editor's transient state) means every
 * write path — the Livewire editor, the MCP/API — is covered uniformly.
 */
class MentionParser
{
    /**
     * The distinct user ids of the @mention nodes in the given rich-text HTML, in
     * first-seen order. Malformed or zero ids are ignored.
     *
     * @return list<int>
     */
    public static function userIds(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $document = HTMLDocument::createFromString('<div>'.$html.'</div>', LIBXML_NOERROR);

        $ids = [];

        foreach ($document->querySelectorAll('span[data-type="mention"]') as $node) {
            $id = (int) $node->getAttribute('data-id');

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
