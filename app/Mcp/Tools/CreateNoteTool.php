<?php

namespace App\Mcp\Tools;

use App\Actions\CreateNote;
use App\Mcp\Concerns\NormalizesPlainText;
use App\Mcp\Concerns\PresentsNotes;
use App\Mcp\Concerns\RequiresWriteAccess;
use App\Support\ReferenceResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Creates a personal note for the authenticated user. The title is required; the body is optional HTML. Optionally attach the note to a project (by its short_name, e.g. "PROJ") the user is a member of, and optionally make it public so that project\'s members can read it. A note can only be public while attached to a project. Notes are referenced by a plain numeric id. Requires a write-access token.')]
class CreateNoteTool extends Tool
{
    use NormalizesPlainText;
    use PresentsNotes;
    use RequiresWriteAccess;

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($denied = $this->denyWithoutWriteAccess($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'project' => ['nullable', 'string'],
            'public' => ['nullable', 'boolean'],
        ], [
            'title.required' => 'You must provide a note title.',
        ]);

        $user = $this->authenticatedUser($request);
        $project = null;

        if (isset($validated['project'])) {
            $project = ReferenceResolver::project($validated['project']);

            if ($project === null || ! $user->can('view', $project)) {
                return Response::error('No project with short_name "'.$validated['project'].'" exists, or you do not have access to it. References look like "PROJ".');
            }
        }

        $note = app(CreateNote::class)->handle(
            $user,
            $this->decodePlainText($validated['title']),
            $validated['body'] ?? null,
            $project,
            (bool) ($validated['public'] ?? false),
        );

        $note->setRelation('project', $project);

        return Response::structured($this->notePayload($note, $user));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()
                ->description('The note title.')
                ->required(),

            'body' => $schema->string()
                ->description('Optional note body, as HTML (sanitized to a small allow-list; unsupported tags are dropped).'),

            'project' => $schema->string()
                ->description('Optional project short_name (e.g. "PROJ") to attach the note to. The user must be a member of the project.'),

            'public' => $schema->boolean()
                ->description('Whether the note is public to its project\'s members. Ignored (treated as false) unless the note is attached to a project.'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return $this->noteSchema($schema);
    }
}
