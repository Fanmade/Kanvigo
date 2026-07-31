<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAuthenticatedUser;
use App\Models\Variable;
use App\Support\ReferenceResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lists a project\'s variables — its named stand-ins for facts that recur or are not decided yet. A variable is written in prose as "[name]" and shows its current value wherever it appears. A variable with a null value is not an error: it is simply undecided, and renders as its own name.')]
#[IsReadOnly]
class ListVariablesTool extends Tool
{
    use ResolvesAuthenticatedUser;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'reference' => ['required', 'string'],
        ], [
            'reference.required' => 'You must provide the project short_name (e.g. "PROJ").',
        ]);

        $project = ReferenceResolver::project($validated['reference']);

        if ($project === null || ! $this->authenticatedUser($request)->can('view', $project)) {
            return Response::error('No project with short_name "'.$validated['reference'].'" exists, or you do not have access to it. References look like "PROJ".');
        }

        return Response::structured([
            'variables' => $project->variables()->get()
                ->map(static fn (Variable $variable): array => [
                    'name' => $variable->name,
                    'value' => $variable->value,
                    'description' => $variable->description,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reference' => $schema->string()
                ->description('The project short_name, 2-4 uppercase letters (e.g. "PROJ").')
                ->required(),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'variables' => $schema->array()->items($schema->object([
                'name' => $schema->string()->description('The variable name, written in prose as "[name]".')->required(),
                'value' => $schema->string()->nullable()->description('What the variable currently stands for, or null when it is still undecided.'),
                'description' => $schema->string()->nullable()->description('An optional note on what the variable is for.'),
            ]))->description('The project\'s variables, in name order.')->required(),
        ];
    }
}
