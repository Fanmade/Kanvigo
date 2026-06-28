<?php

namespace App\Support;

use App\Models\User;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Turns @mention nodes in stored rich-text into links to the mentioned user's
 * profile page.
 *
 * Mentions are saved as atomic inline spans carrying the user's id, e.g.
 * {@code <span class="mention" data-type="mention" data-id="5">@Name</span>}.
 * On output each becomes
 * {@code <a class="mention" data-type="mention" data-id="5" href="/users/5">@Name</a>}
 * so it is clickable wherever the content is shown — mirroring how #task
 * references are already rendered as links. The rewrite runs on the saved HTML
 * (not the editor's transient state), so every write path — the Livewire editor,
 * the MCP/API — is covered uniformly, and the editor itself keeps editing the
 * plain spans.
 */
class MentionLinker
{
    public static function link(?string $html): string
    {
        $html ??= '';

        // Cheap bail-out: skip the DOM round-trip when there is nothing to link.
        if (! str_contains($html, 'data-type="mention"')) {
            return $html;
        }

        $document = HTMLDocument::createFromString('<div>'.$html.'</div>', LIBXML_NOERROR);

        $spans = $document->querySelectorAll('span[data-type="mention"]');

        // Resolve the mentioned users' numeric ids to their opaque public ids in
        // a single query, so the profile links never expose the sequential id.
        $publicIds = self::publicIdsFor($spans);

        foreach ($spans as $span) {
            $id = (int) $span->getAttribute('data-id');

            if (! isset($publicIds[$id])) {
                continue;
            }

            $anchor = $document->createElement('a');
            $anchor->setAttribute('class', $span->getAttribute('class') !== '' ? $span->getAttribute('class') : 'mention');
            $anchor->setAttribute('data-type', 'mention');
            $anchor->setAttribute('data-id', (string) $id);

            if ($span->hasAttribute('data-label')) {
                $anchor->setAttribute('data-label', $span->getAttribute('data-label'));
            }

            $anchor->setAttribute('href', route('users.show', $publicIds[$id]));
            $anchor->textContent = $span->textContent;

            $span->parentNode?->replaceChild($anchor, $span);
        }

        $wrapper = $document->querySelector('div');

        return $wrapper instanceof Element ? $wrapper->innerHTML : $html;
    }

    /**
     * Map the valid numeric mention ids in the given span nodes to their users'
     * public ids, in one query. Ids that are malformed or belong to no user are
     * absent from the result, so those mentions are left as plain spans.
     *
     * @param  iterable<Element>  $spans
     * @return array<int, string>
     */
    private static function publicIdsFor(iterable $spans): array
    {
        $ids = [];

        foreach ($spans as $span) {
            $id = (int) $span->getAttribute('data-id');

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->pluck('public_id', 'id')
            ->all();
    }
}
