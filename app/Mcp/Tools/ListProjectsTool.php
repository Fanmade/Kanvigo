<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lists the projects the authenticated user is a member of, with their short_name, title, description and story count.')]
#[IsReadOnly]
class ListProjectsTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): ResponseFactory
    {
        $projects = $request->user()->projects()
            ->withCount('stories')
            ->orderBy('title')
            ->get()
            ->map(static fn (Project $project): array => [
                'short_name' => $project->short_name,
                'title' => $project->title,
                'description' => $project->description,
                'story_count' => $project->stories_count,
            ]);

        return Response::structured([
            'projects' => $projects->all(),
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
            //
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
            'projects' => $schema->array()->items($schema->object([
                'short_name' => $schema->string()->description('The project short name (2-4 uppercase letters).')->required(),
                'title' => $schema->string()->description('The project title.')->required(),
                'description' => $schema->string()->description('The project description; may be null.'),
                'story_count' => $schema->integer()->description('Number of stories in the project.')->required(),
            ]))->description('The projects the authenticated user is a member of.')->required(),
        ];
    }
}
