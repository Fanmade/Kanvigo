<?php

namespace App\Mcp\Tools;

use App\Actions\UpdateNote;
use App\Mcp\Concerns\NormalizesPlainText;
use App\Mcp\Concerns\PresentsNotes;
use App\Mcp\Concerns\RequiresWriteAccess;
use App\Models\Note;
use App\Support\ReferenceResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Updates one of the authenticated user\'s own notes, by its numeric id. Any of title, body, the attached project and public flag may be changed; omitted fields are left as-is. Pass "project" as a project short_name to attach (or move) the note, or as an empty value to detach it. A note can only be public while attached to a project — detaching or never attaching forces it private. Requires a write-access token; only the note\'s owner may update it.')]
class UpdateNoteTool extends Tool
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
            'id' => ['required', 'integer'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'project' => ['nullable', 'string'],
            'public' => ['nullable', 'boolean'],
        ], [
            'id.required' => 'You must provide the numeric note id.',
            'title.required' => 'The note title cannot be empty.',
        ]);

        $user = $this->authenticatedUser($request);
        $note = Note::with(['project', 'convertedTask.project'])->whereKey($validated['id'])->first();

        if ($note === null || ! $user->can('update', $note)) {
            return Response::error('No note with id '.$validated['id'].' exists, or you do not own it.');
        }

        $title = $request->has('title') ? $this->decodePlainText($validated['title']) : $note->title;
        $body = $request->has('body') ? ($validated['body'] ?? null) : $note->body;
        $isPublic = $request->has('public') ? (bool) ($validated['public'] ?? false) : $note->is_public;

        $project = $note->project;

        if ($request->has('project')) {
            $reference = $validated['project'] ?? null;

            if ($reference === null || $reference === '') {
                $project = null;
            } else {
                $project = ReferenceResolver::project($reference);

                if ($project === null || ! $user->can('view', $project)) {
                    return Response::error('No project with short_name "'.$reference.'" exists, or you do not have access to it. References look like "PROJ".');
                }
            }
        }

        app(UpdateNote::class)->handle($note, $title, $body, $project, $isPublic);

        $note->setRelation('project', $project);

        return Response::structured($this->notePayload($note, $user));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->description('The numeric id of the note to update.')
                ->required(),

            'title' => $schema->string()
                ->description('A new title. Omit to leave unchanged.'),

            'body' => $schema->string()
                ->description('A new body, as HTML (sanitized). Pass an empty value to clear it; omit to leave unchanged.'),

            'project' => $schema->string()
                ->description('A project short_name (e.g. "PROJ") to attach or move the note to, or an empty value to detach it. Omit to leave the attachment unchanged.'),

            'public' => $schema->boolean()
                ->description('Whether the note is public to its project. Forced to false unless the note is attached to a project. Omit to leave unchanged.'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The note id.')->required(),
            'title' => $schema->string()->description('The note title.')->required(),
            'body' => $schema->string()->description('The note body as HTML; may be null.'),
            'project' => $schema->string()->description('The attached project short_name, or null.'),
            'is_public' => $schema->boolean()->description('Whether the note is public to its project.')->required(),
            'owned' => $schema->boolean()->description('Whether the authenticated user owns the note.')->required(),
            'converted_task' => $schema->string()->description('The task reference this note was converted into, or null.'),
        ];
    }
}
