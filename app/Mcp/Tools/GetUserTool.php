<?php

namespace App\Mcp\Tools;

use App\Audit\AccessAudit;
use App\Models\User;
use App\Support\Facades\Audit;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Gets a single user by their stable id (the "id" reported on assignees, comment authors and elsewhere). The name is always returned; the email is only included when you are entitled to see it — a project you share with the user, or user-administration access. Users you share no project with are not accessible.')]
#[IsReadOnly]
class GetUserTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'id' => ['required', 'string'],
        ], [
            'id.required' => 'You must provide the user id (the "id" reported on assignees and comment authors).',
        ]);

        $viewer = $request->user();
        $user = User::query()->where('public_id', $validated['id'])->first();

        // Resolvable within the collaboration boundary, plus user administrators;
        // the email is gated again below. A stranger is reported as not found.
        if ($user === null || ($viewer->cannot('view', $user) && $viewer->cannot('viewContactInfo', $user))) {
            return Response::error('No user with id "'.$validated['id'].'" exists, or you do not share a project with them.');
        }

        $seesContactInfo = $viewer->can('viewContactInfo', $user);

        // Audit only the disclosure of another member's contact info — seeing
        // your own is not "who looked at whom" and would be high-volume noise.
        if ($seesContactInfo && $user->getKey() !== $viewer->getAuthIdentifier()) {
            Audit::record(AccessAudit::contactInfoViewed($user));
        }

        return Response::structured([
            'id' => $user->public_id,
            'name' => $user->name,
            'email' => $seesContactInfo ? $user->email : null,
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
            'id' => $schema->string()
                ->description('The user id — the stable handle reported as "id" on assignees, comment authors and other user references.')
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
            'id' => $schema->string()->description('The user\'s stable id.')->required(),
            'name' => $schema->string()->description('The user\'s display name.')->required(),
            'email' => $schema->string()->nullable()->description('The user\'s email, or null when you are not entitled to see it.'),
        ];
    }
}
