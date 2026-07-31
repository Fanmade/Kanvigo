<?php

namespace App\Http\Resources\Concerns;

use App\Contracts\UsesVariables;
use App\Queries\VariablesUsedIn;

/**
 * Serializes the project variables an item's content names, for the API detail
 * resources.
 *
 * The body is always returned exactly as stored, `[name]` intact, with the values
 * alongside it — the same shape {@see SerializesReferences} uses for links.
 * Resolving the names into the body would break a read-edit-write round trip: the
 * client would write back baked values and wipe out every usage.
 */
trait SerializesVariables
{
    /**
     * @return array{variables: list<array{name: string, value: string|null}>}
     */
    protected function variableList(UsesVariables $item): array
    {
        $variables = [];

        foreach (app(VariablesUsedIn::class)->handle($item) as $variable) {
            $variables[] = ['name' => $variable->name, 'value' => $variable->value];
        }

        return ['variables' => $variables];
    }
}
