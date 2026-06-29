<?php

namespace App\Mcp\Concerns;

use App\Enums\RelationshipType;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * Serializes a task's relationships for the MCP tools: the references of the
 * related tasks grouped by relationship keyword (blocked_by, blocks, relates,
 * duplicates, …) and whether any blocker is still unfinished.
 */
trait ExposesDependencies
{
    /**
     * Build the relationship payload for a task, eager-loading the linked items
     * (and the relations needed to compute their references) in one pass to avoid
     * N+1 queries. Every relationship keyword is present, mapping to a list of
     * related references, alongside the `is_blocked` flag.
     *
     * @return array<string, array<int, string>|bool>
     */
    protected function dependencyPayload(Task $item): array
    {
        return $item->relationshipPayload();
    }

    /**
     * The output-schema fields describing the relationship reference arrays plus
     * the is_blocked flag — shared by the read and write relationship tools.
     *
     * @return array<string, Type>
     */
    protected function dependencySchema(JsonSchema $schema): array
    {
        $fields = [];

        foreach (RelationshipType::keywords() as $keyword) {
            $fields[$keyword] = $schema->array()->items($schema->string())
                ->description('References of the tasks this item "'.$keyword.'".')
                ->required();
        }

        $fields['is_blocked'] = $schema->boolean()
            ->description('Whether the item has a blocker that is not yet complete.')
            ->required();

        return $fields;
    }
}
