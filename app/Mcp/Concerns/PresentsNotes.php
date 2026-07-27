<?php

namespace App\Mcp\Concerns;

use App\Models\Note;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

trait PresentsNotes
{
    use ResolvesAuthenticatedUser;

    /**
     * The structured payload for a single note.
     *
     * @return array<string, mixed>
     */
    protected function notePayload(Note $note, User $user, bool $withBody = true): array
    {
        $payload = [
            'id' => $note->id,
            'title' => $note->title,
            'project' => $note->project?->short_name,
            'is_public' => $note->is_public,
            'owned' => $note->user_id === $user->id,
            'converted_task' => $note->convertedTask?->reference,
        ];

        if ($withBody) {
            $payload['body'] = $note->body;
        }

        return $payload;
    }

    /**
     * The output-schema fields for a single note, shared by the note tools so
     * their shapes never drift. Pass $convertedTaskRequired where the field is
     * always present (the convert tool, which has just produced the task).
     *
     * @return array<string, Type>
     */
    protected function noteSchema(JsonSchema $schema, bool $convertedTaskRequired = false): array
    {
        $convertedTask = $schema->string()
            ->description('The reference of the task this note was converted into (e.g. "PROJ-42"), or null.');

        return [
            'id' => $schema->integer()->description('The note id.')->required(),
            'title' => $schema->string()->description('The note title.')->required(),
            'body' => $schema->string()->nullable()->description('The note body as HTML; may be null.'),
            'project' => $schema->string()->nullable()->description('The attached project short_name, or null.'),
            'is_public' => $schema->boolean()->description('Whether the note is public to its project.')->required(),
            'owned' => $schema->boolean()->description('Whether the authenticated user owns the note.')->required(),
            'converted_task' => $convertedTaskRequired ? $convertedTask->required() : $convertedTask->nullable(),
        ];
    }
}
