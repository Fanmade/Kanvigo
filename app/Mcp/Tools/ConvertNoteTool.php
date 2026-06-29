<?php

namespace App\Mcp\Tools;

use App\Actions\ConvertNote;
use App\Actions\CreateTask;
use App\Mcp\Concerns\PresentsNotes;
use App\Mcp\Concerns\RequiresWriteAccess;
use App\Mcp\Concerns\ResolvesTaskCreationReferences;
use App\Models\Note;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Converts one of the authenticated user\'s own notes (by its numeric id) into a task in a project (by its short_name, e.g. "PROJ") the user is a member of. The new task takes the note\'s title and body, and may be nested under a "parent" task (e.g. "PROJ-42"). The note is kept and linked to the task it produced. Requires a write-access token; only the note\'s owner may convert it.')]
class ConvertNoteTool extends Tool
{
    use PresentsNotes;
    use RequiresWriteAccess;
    use ResolvesTaskCreationReferences;

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($denied = $this->denyWithoutWriteAccess($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'id' => ['required', 'integer'],
            'reference' => ['required', 'string'],
            'parent' => ['nullable', 'string'],
        ], [
            'id.required' => 'You must provide the numeric note id to convert.',
            'reference.required' => 'You must provide the project short_name to create the task in (e.g. "PROJ").',
        ]);

        $user = $this->authenticatedUser($request);
        $note = Note::with('convertedTask.project')->whereKey($validated['id'])->first();

        if ($note === null || ! $user->can('update', $note)) {
            return Response::error('No note with id '.$validated['id'].' exists, or you do not own it.');
        }

        $project = $this->resolveTaskProject($request, $validated['reference']);

        if ($project instanceof Response) {
            return $project;
        }

        $parent = $this->resolveParentTask($request, $validated['parent'] ?? null, $project);

        if ($parent instanceof Response) {
            return $parent;
        }

        try {
            $task = app(CreateTask::class)->handle(
                $project,
                $note->title,
                $note->body,
                null,
                null,
                null,
                $parent,
            );
        } catch (InvalidArgumentException) {
            return Response::error('The task cannot be nested under "'.$validated['parent'].'": it would exceed the maximum nesting depth.');
        }

        app(ConvertNote::class)->handle($note, $task);

        $task->setRelation('project', $project);
        $note->setRelation('project', $project)->setRelation('convertedTask', $task);

        return Response::structured($this->notePayload($note, $user));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->description('The numeric id of the note to convert.')
                ->required(),

            'reference' => $schema->string()
                ->description('The short_name of the project to create the task in (e.g. "PROJ").')
                ->required(),

            'parent' => $schema->string()
                ->description('Optional parent task reference (e.g. "PROJ-42") to nest the new task under. Must be a task in the same project.'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return $this->noteSchema($schema, convertedTaskRequired: true);
    }
}
