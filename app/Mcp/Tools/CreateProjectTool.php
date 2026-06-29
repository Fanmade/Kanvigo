<?php

namespace App\Mcp\Tools;

use App\Actions\CreateProject;
use App\Mcp\Concerns\NormalizesPlainText;
use App\Mcp\Concerns\RequiresWriteAccess;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Creates a new project and adds the authenticated user as a member. Requires a write-access token and the "create-projects" permission.')]
class CreateProjectTool extends Tool
{
    use NormalizesPlainText;
    use RequiresWriteAccess;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        if ($denied = $this->denyWithoutWriteAccess($request)) {
            return $denied;
        }

        $user = $request->user();

        if (! $user->can('create', Project::class)) {
            return Response::error('You do not have permission to create projects.');
        }

        $data = $request->all();
        $data['short_name'] = strtoupper(trim((string) ($data['short_name'] ?? '')));

        $validated = Validator::validate($data, [
            'title' => ['required', 'string', 'max:255'],
            'short_name' => Project::shortNameRules(),
            'description' => ['nullable', 'string'],
        ], [
            'title.required' => 'You must provide a project title.',
            'short_name.required' => 'You must provide a short_name: 2-4 letters used to reference the project (e.g. "PROJ").',
            'short_name.alpha' => 'The short_name must contain only letters.',
            'short_name.min' => 'The short_name must be 2-4 letters long.',
            'short_name.max' => 'The short_name must be 2-4 letters long.',
            'short_name.unique' => 'That short_name is already taken. Choose another.',
            'short_name.not_in' => 'That short_name is reserved. Choose another.',
        ]);

        $project = app(CreateProject::class)->handle(
            User::findOrFail((int) $user->getAuthIdentifier()),
            $this->decodePlainText($validated['title']),
            $validated['short_name'],
            $validated['description'] ?? null,
        );

        return Response::structured([
            'short_name' => $project->short_name,
            'title' => $project->title,
            'description' => $project->description,
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
            'title' => $schema->string()
                ->description('The project title.')
                ->required(),

            'short_name' => $schema->string()
                ->description('A 2-4 letter code used to reference the project (e.g. "PROJ"). Stored uppercase and must be unique.')
                ->required(),

            'description' => $schema->string()
                ->description('Optional project description, as HTML (sanitized to a small allow-list; unsupported tags are dropped).'),
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
            'short_name' => $schema->string()->description('The created project short name.')->required(),
            'title' => $schema->string()->description('The created project title.')->required(),
            'description' => $schema->string()->description('The project description as HTML; may be null.'),
        ];
    }
}
