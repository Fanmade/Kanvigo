<?php

namespace App\Mcp\Concerns;

use App\Contracts\Referenceable;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;

/**
 * Serializes an item's cross-references for the MCP tools: the references of the
 * tasks and docs it links to, and of those linking back to it.
 *
 * References are pure navigation — unlike a dependency they never block anything
 * — and cross both item kinds, so each side is a flat list of "PROJ-42" /
 * "PROJ-D3" references. Items the caller may not view are left out, so a link can
 * never disclose a draft doc or a project they are not a member of.
 */
trait ExposesReferences
{
    /**
     * The reference payload for an item: what it links to, and what links to it.
     *
     * @return array{references: list<string>, referenced_by: list<string>}
     */
    protected function referencePayload(Model&Referenceable $item, User $user): array
    {
        $item->loadMissing($item::referenceItemsEagerLoad());

        return [
            'references' => $this->referenceList($item->references(), $user),
            'referenced_by' => $this->referenceList($item->referencedBy(), $user),
        ];
    }

    /**
     * The output-schema fields matching {@see referencePayload()}.
     *
     * @return array<string, Type>
     */
    protected function referenceSchema(JsonSchema $schema): array
    {
        return [
            'references' => $schema->array()->items($schema->string())
                ->description('References of the tasks ("PROJ-42") and docs ("PROJ-D3") this item links to.')
                ->required(),

            'referenced_by' => $schema->array()->items($schema->string())
                ->description('References of the tasks and docs that link to this item (its backlinks).')
                ->required(),
        ];
    }

    /**
     * The references of the linked items the user may view.
     *
     * @param  Collection<int, Model&Referenceable>  $items
     * @return list<string>
     */
    private function referenceList(Collection $items, User $user): array
    {
        $references = [];

        foreach ($items as $item) {
            if ($user->can('view', $item)) {
                $references[] = $item->getReference();
            }
        }

        return $references;
    }
}
