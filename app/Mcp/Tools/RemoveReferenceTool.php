<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ExposesReferences;
use App\Mcp\Concerns\RequiresWriteAccess;
use App\Mcp\Concerns\ResolvesAuthenticatedUser;
use App\Mcp\Concerns\ResolvesReferencePair;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Removes the cross-reference from "reference" to "related_reference". Only that direction is unlinked — a link the other way round is left in place. A reference written inline in the item\'s text ("#PROJ-42") comes back the next time the text is saved; remove it from the text instead. Requires a write-access token; the user must be able to change the item.')]
class RemoveReferenceTool extends Tool
{
    use ExposesReferences;
    use RequiresWriteAccess;
    use ResolvesAuthenticatedUser;
    use ResolvesReferencePair;

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($denied = $this->denyWithoutWriteAccess($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'reference' => ['required', 'string'],
            'related_reference' => ['required', 'string'],
        ], [
            'reference.required' => 'You must provide the reference of the item whose link you are removing (e.g. "PROJ-42" or "PROJ-D3").',
            'related_reference.required' => 'You must provide the reference of the linked item to unlink.',
        ]);

        $resolution = $this->resolveReferencePair($request, $validated['reference'], $validated['related_reference']);

        if ($resolution->failed()) {
            return $resolution->error();
        }

        [$item, $related] = $resolution->pair();

        if (! $item->references()->contains(static fn ($linked): bool => $linked->is($related))) {
            return Response::error('"'.$item->reference.'" does not link to "'.$related->reference.'".');
        }

        $item->removeReference($related);

        return Response::structured([
            'reference' => $item->reference,
            'related' => $related->reference,
            ...$this->referencePayload($item, $this->authenticatedUser($request)),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reference' => $schema->string()
                ->description('The reference of the item whose link you are removing — a task ("PROJ-42") or a doc ("PROJ-D3").')
                ->required(),

            'related_reference' => $schema->string()
                ->description('The reference of the linked item to unlink.')
                ->required(),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'reference' => $schema->string()->description('The reference of the item whose links changed.')->required(),
            'related' => $schema->string()->description('The reference of the item that was unlinked.')->required(),
            ...$this->referenceSchema($schema),
        ];
    }
}
