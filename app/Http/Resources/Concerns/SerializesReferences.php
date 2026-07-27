<?php

namespace App\Http\Resources\Concerns;

use App\Contracts\Referenceable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Serializes an item's cross-references for the API detail resources: the
 * references of the tasks and docs it links to, and of those linking back to it.
 *
 * Both lists are filtered by view access, so a link never discloses a draft doc
 * or an item in a project the caller cannot see.
 */
trait SerializesReferences
{
    /**
     * @return array{references: list<string>, referenced_by: list<string>}
     */
    protected function referenceLists(Model&Referenceable $item): array
    {
        return [
            'references' => $this->viewableReferences($item->references()),
            'referenced_by' => $this->viewableReferences($item->referencedBy()),
        ];
    }

    /**
     * The references of the linked items the caller may view.
     *
     * @param  Collection<int, Model&Referenceable>  $items
     * @return list<string>
     */
    private function viewableReferences(Collection $items): array
    {
        $user = Auth::user();
        $references = [];

        foreach ($items as $item) {
            if ($user?->can('view', $item)) {
                $references[] = $item->getReference();
            }
        }

        return $references;
    }
}
