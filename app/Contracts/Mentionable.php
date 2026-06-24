<?php

namespace App\Contracts;

use App\Concerns\HasMentions;

/**
 * A model whose rich-text content can carry @mentions of users — a task or
 * project description, or a comment body. The mention index ({@see HasMentions})
 * is reconciled on save against the users this model permits to be mentioned.
 */
interface Mentionable
{
    /**
     * The ids of users who may be @mentioned on this model (e.g. project members).
     * Mentions of anyone outside this set are dropped, so directly-written HTML
     * cannot mention — or notify — users without access.
     *
     * @return list<int>
     */
    public function mentionableUserIds(): array;

    /**
     * Reconcile the stored mention index with the current content and return the
     * sync changes, so callers can act on the newly-mentioned users.
     *
     * @return array{attached: list<int>, detached: list<int>, updated: list<int>}
     */
    public function syncMentions(): array;
}
