<?php

namespace App\Concerns;

use App\Contracts\Referenceable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

/**
 * Adds the cross-reference panels — the items this one links to, and the ones
 * linking back to it — to a page component showing a single task or doc.
 *
 * Both lists are filtered by view access: a link may point at a draft doc, or at
 * an item in a project the reader is not a member of, and neither may be
 * disclosed just because something links to it.
 *
 * @see HasReferences
 */
trait ShowsReferences
{
    /**
     * The item whose references are shown.
     */
    abstract protected function referenceable(): Model&Referenceable;

    /**
     * The items this one links to.
     *
     * @return Collection<int, Model&Referenceable>
     */
    #[Computed]
    public function linkedItems(): Collection
    {
        return $this->viewableItems($this->referenceable()->references());
    }

    /**
     * The items linking to this one (its backlinks).
     *
     * @return Collection<int, Model&Referenceable>
     */
    #[Computed]
    public function backlinks(): Collection
    {
        return $this->viewableItems($this->referenceable()->referencedBy());
    }

    /**
     * Keep only the items the viewer may see.
     *
     * @param  Collection<int, Model&Referenceable>  $items
     * @return Collection<int, Model&Referenceable>
     */
    private function viewableItems(Collection $items): Collection
    {
        return $items
            ->filter(static fn (Model&Referenceable $item): bool => Gate::allows('view', $item))
            ->values();
    }
}
