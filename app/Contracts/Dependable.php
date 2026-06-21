<?php

namespace App\Contracts;

/**
 * An item that can take part in dependency links (a Task). The
 * HasDependencies trait uses this to flag blocked items: a blocker no longer
 * holds back the items it blocks once it is complete.
 */
interface Dependable
{
    /**
     * Whether this item counts as finished for dependency purposes — a Done task.
     */
    public function isComplete(): bool;
}
