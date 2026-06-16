<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\Story;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lists the stories for a project, identified by its short_name. Only projects the authenticated user is a member of are accessible.')]
#[IsReadOnly]
class ListStoriesTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'short_name' => ['required', 'string'],
        ], [
            'short_name.required' => 'You must provide the project short_name (e.g. "PROJ").',
        ]);

        $user = $request->user();

        $project = Project::query()
            ->where('short_name', $validated['short_name'])
            ->with(['stories.project'])
            ->first();

        if ($project === null || ! $user->can('view', $project)) {
            return Response::error('No project named "'.$validated['short_name'].'" exists, or you do not have access to it.');
        }

        return Response::structured([
            'stories' => $project->stories->map(static fn (Story $story): array => [
                'reference' => $story->reference,
                'title' => $story->title,
                'description' => $story->description,
            ])->all(),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'short_name' => $schema->string()
                ->description('The project short_name, 2-4 uppercase letters (e.g. "PROJ").')
                ->required(),
        ];
    }

    /**
     * Get the tool's output schema.
     *
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'stories' => $schema->array()->items($schema->object([
                'reference' => $schema->string()->description('The story reference, e.g. "PROJ1".')->required(),
                'title' => $schema->string()->description('The story title.')->required(),
                'description' => $schema->string()->description('The story description; may be null.'),
            ]))->description('The stories in the project.')->required(),
        ];
    }
}
