<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\PresentsNotes;
use App\Models\Note;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Gets a single note by its numeric id, including its body. Accessible if the authenticated user owns the note, or it is public and attached to a project the user is a member of.')]
#[IsReadOnly]
class GetNoteTool extends Tool
{
    use PresentsNotes;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'id' => ['required', 'integer'],
        ], [
            'id.required' => 'You must provide the numeric note id.',
        ]);

        $user = $this->authenticatedUser($request);
        $note = Note::with(['project', 'convertedTask.project'])->whereKey($validated['id'])->first();

        if ($note === null || ! $user->can('view', $note)) {
            return Response::error('No note with id '.$validated['id'].' exists, or you do not have access to it.');
        }

        return Response::structured($this->notePayload($note, $user));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->description('The numeric note id.')
                ->required(),
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
