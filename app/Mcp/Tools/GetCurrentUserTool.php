<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Gets the user you are acting as — your own stable id, name and email. Use the id with set-assignees to assign a task to yourself (e.g. to honour "assign this to me"), since assignees are referenced by that id.')]
#[IsReadOnly]
class GetCurrentUserTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return Response::error('There is no authenticated user for this request.');
        }

        return Response::structured([
            'id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
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
            'id' => $schema->string()->description('Your stable user id — pass it to set-assignees to assign a task to yourself.')->required(),
            'name' => $schema->string()->description('Your display name.')->required(),
            'email' => $schema->string()->description('Your email address.')->required(),
        ];
    }
}
