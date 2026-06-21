<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresWriteAccess;
use App\Models\Project;
use App\Support\ReferenceResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Adds a comment to a project ("PROJ") or task ("PROJ-42"), identified by its reference. Requires a write-access token; the user must be a member of the project.')]
class AddCommentTool extends Tool
{
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
            'reference' => ['required', 'string'],
            'body' => ['required', 'string', 'max:5000'],
        ], [
            'reference.required' => 'You must provide the reference of the project ("PROJ") or task ("PROJ-42") to comment on.',
            'body.required' => 'You must provide the comment body.',
        ]);

        $commentable = ReferenceResolver::commentable($validated['reference']);

        if ($commentable === null || ! $request->user()->can('view', $commentable)) {
            return Response::error('No project or task with reference "'.$validated['reference'].'" exists, or you do not have access to it.');
        }

        $comment = $commentable->comments()->create([
            'user_id' => $request->user()->getAuthIdentifier(),
            'body' => $validated['body'],
        ]);

        $commentable->recordActivity('commented');

        return Response::structured([
            'id' => $comment->id,
            'on' => $commentable instanceof Project ? $commentable->short_name : $commentable->reference,
            'body' => $comment->body,
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
            'reference' => $schema->string()
                ->description('The reference of the item to comment on: a project ("PROJ") or task ("PROJ-42").')
                ->required(),

            'body' => $schema->string()
                ->description('The comment text.')
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
            'id' => $schema->integer()->description('The created comment id.')->required(),
            'on' => $schema->string()->description('The reference of the item the comment was added to.')->required(),
            'body' => $schema->string()->description('The comment text.')->required(),
        ];
    }
}
