<?php

namespace App\Contracts;

use App\Concerns\HasReferences;
use App\Enums\ReferenceOrigin;
use App\Models\Reference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * An item that can take part in cross-references (a Task or a Doc). It constrains
 * what {@see HasReferences} will link — so a reference can never point at an
 * unrelated model (a User, say) — and names the operations the surfaces listing
 * links rely on. {@see HasReferences} implements all of them.
 *
 * Every referenceable item is addressed by a human-readable reference: "PROJ-42"
 * for a task, "PROJ-D3" for a doc.
 */
interface Referenceable
{
    /**
     * The item's human-readable reference, e.g. "PROJ-42" or "PROJ-D3".
     */
    public function getReference(): string;

    /**
     * The items this one references.
     *
     * @return Collection<int, Model&Referenceable>
     */
    public function references(): Collection;

    /**
     * The items that reference this one (its backlinks).
     *
     * @return Collection<int, Model&Referenceable>
     */
    public function referencedBy(): Collection;

    /**
     * Record that this item references the given one.
     */
    public function addReference(Model&Referenceable $target, ReferenceOrigin $origin = ReferenceOrigin::Manual): Reference;

    /**
     * Remove this item's reference to the given one.
     */
    public function removeReference(Model&Referenceable $target): void;

    /**
     * The eager-load spec for an item's reference targets and sources, so
     * resolving the linked items stays N+1-free.
     *
     * @return array<string, \Closure>
     */
    public static function referenceItemsEagerLoad(): array;
}
