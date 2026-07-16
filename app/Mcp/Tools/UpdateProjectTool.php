<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\NormalizesPlainText;
use App\Mcp\Concerns\RequiresWriteAccess;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Updates a project\'s title and/or description, identified by its short_name (e.g. "PROJ"). The change is recorded in the audit trail. Requires a write-access token, and the user must be able to manage the project\'s settings.')]
class UpdateProjectTool extends Tool
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

        $validated = $request->validate([
            'short_name' => ['required', 'string'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ], [
            'short_name.required' => 'You must provide the project short_name (e.g. "PROJ").',
        ]);

        $project = Project::query()->where('short_name', $validated['short_name'])->first();

        if ($project === null || ! $request->user()->can('manageSettings', $project)) {
            return Response::error('No project named "'.$validated['short_name'].'" exists, or you do not have permission to manage its settings.');
        }

        $updates = [];

        if ($request->has('title')) {
            $title = trim((string) $this->decodePlainText($validated['title']));

            if ($title === '') {
                return Response::error('The project title cannot be empty.');
            }

            $updates['title'] = $title;
        }

        if ($request->has('description')) {
            // The model sanitizes the HTML to the allow-list on assignment.
            $updates['description'] = $validated['description'];
        }

        if ($updates === []) {
            return Response::error('Provide a title and/or description to update.');
        }

        // A plain update audits each changed field through the LogsActivity
        // updated-hook (title_changed / description_changed), exactly like the UI.
        $project->update($updates);

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
            'short_name' => $schema->string()
                ->description('The short_name of the project to update (e.g. "PROJ").')
                ->required(),

            'title' => $schema->string()
                ->description('New title for the project.'),

            'description' => $schema->string()
                ->description('New description for the project, as HTML (sanitized to a small allow-list; unsupported tags are dropped). Pass an empty string to clear it.'),
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
            'short_name' => $schema->string()->description('The project short name.')->required(),
            'title' => $schema->string()->description('The updated project title.')->required(),
            'description' => $schema->string()->nullable()->description('The project description as HTML; may be null.'),
        ];
    }
}
