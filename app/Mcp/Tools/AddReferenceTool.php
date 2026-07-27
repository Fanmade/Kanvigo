<?php

namespace App\Mcp\Tools;

use App\Enums\ReferenceOrigin;
use App\Mcp\Concerns\ExposesReferences;
use App\Mcp\Concerns\RequiresWriteAccess;
use App\Mcp\Concerns\ResolvesAuthenticatedUser;
use App\Mcp\Concerns\ResolvesReferencePair;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Cross-links two items — tasks ("PROJ-42") and docs ("PROJ-D3") in any combination — so that "reference" links to "related_reference", which in turn gains a backlink. A reference is pure navigation: unlike a dependency it never blocks anything, and links may be circular. Links made here are kept even when the items\' text is edited (unlike the ones written inline as "#PROJ-42" in a body, which follow the text). Linking an item to itself is rejected. Requires a write-access token; the user must be able to change the item and to view the one it links to.')]
class AddReferenceTool extends Tool
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
            'reference.required' => 'You must provide the reference of the item the link is written on (e.g. "PROJ-42" or "PROJ-D3").',
            'related_reference.required' => 'You must provide the reference of the item to link to.',
        ]);

        $resolution = $this->resolveReferencePair($request, $validated['reference'], $validated['related_reference']);

        if ($resolution->failed()) {
            return $resolution->error();
        }

        [$item, $related] = $resolution->pair();

        try {
            $item->addReference($related, ReferenceOrigin::Manual);
        } catch (InvalidArgumentException) {
            return Response::error('An item cannot reference itself.');
        }

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
                ->description('The reference of the item the link is written on — a task ("PROJ-42") or a doc ("PROJ-D3").')
                ->required(),

            'related_reference' => $schema->string()
                ->description('The reference of the item to link to — a task ("PROJ-42") or a doc ("PROJ-D3").')
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
            'related' => $schema->string()->description('The reference of the item that was linked.')->required(),
            ...$this->referenceSchema($schema),
        ];
    }
}
