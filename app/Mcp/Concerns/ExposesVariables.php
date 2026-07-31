<?php

namespace App\Mcp\Concerns;

use App\Contracts\UsesVariables;
use App\Queries\VariablesUsedIn;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * Serializes the project variables an item's content names, for the MCP read and
 * write tools.
 *
 * The body itself is always returned exactly as stored, with `[name]` intact, and
 * the values ride alongside it — the same shape {@see ExposesReferences} uses for
 * links. Resolving the names into the body instead would break the read-edit-write
 * round trip: the client would write back baked values and wipe out every usage.
 */
trait ExposesVariables
{
    /**
     * The variable payload for an item: the defined variables its content uses,
     * and what each currently stands for.
     *
     * @return array{variables: list<array{name: string, value: string|null}>}
     */
    protected function variablePayload(UsesVariables $item): array
    {
        $variables = [];

        foreach (app(VariablesUsedIn::class)->handle($item) as $variable) {
            $variables[] = ['name' => $variable->name, 'value' => $variable->value];
        }

        return ['variables' => $variables];
    }

    /**
     * The output-schema fields matching {@see variablePayload()}.
     *
     * @return array<string, Type>
     */
    protected function variableSchema(JsonSchema $schema): array
    {
        return [
            'variables' => $schema->array()->items($schema->object([
                'name' => $schema->string()->description('The variable name, written in the content as "[name]".')->required(),
                'value' => $schema->string()->nullable()->description('What the variable currently stands for, or null when no value has been decided yet.'),
            ]))->description('The project variables this content uses. The content keeps the literal "[name]" — resolve it against this list when reading, and leave it untouched when writing back.')->required(),
        ];
    }
}
