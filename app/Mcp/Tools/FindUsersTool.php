<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Finds people you share a project with, by a name or email fragment, so you can get the stable id needed to assign a task (e.g. to honour "assign this to Dana"). Returns matching users as id + name — you can only find users you share a project with, and only those users can be assigned. To read a user\'s email, pass their id to get-user.')]
#[IsReadOnly]
class FindUsersTool extends Tool
{
    /**
     * The most matches returned in one call. A search that hits this many is
     * reported as truncated so the agent narrows its query rather than being
     * silently cut off.
     */
    private const int MAX_RESULTS = 25;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:1'],
        ], [
            'query.required' => 'You must provide a name or email fragment to search for.',
        ]);

        $callerId = $request->user()?->getAuthIdentifier();
        $term = '%'.$validated['query'].'%';

        $matches = User::query()
            // Only users who share at least one project with the caller — the same
            // collaboration boundary as get-user, and exactly the set that can be
            // assigned to the caller's tasks.
            ->whereHas('projects', static fn (Builder $query): Builder => $query->whereHas(
                'members',
                static fn (Builder $member): Builder => $member->whereKey($callerId),
            ))
            ->where(static fn (Builder $query): Builder => $query
                ->whereLike('name', $term)
                ->orWhereLike('email', $term))
            ->orderBy('name')
            ->limit(self::MAX_RESULTS + 1)
            ->get(['public_id', 'name']);

        $truncated = $matches->count() > self::MAX_RESULTS;

        return Response::structured([
            'users' => $matches->take(self::MAX_RESULTS)->map(static fn (User $user): array => [
                'id' => $user->public_id,
                'name' => $user->name,
            ])->all(),
            'truncated' => $truncated,
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
            'query' => $schema->string()
                ->description('A name or email fragment to match (case-insensitive). Matches users you share a project with.')
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
            'users' => $schema->array()->items($schema->object([
                'id' => $schema->string()->description('The user\'s stable id — pass it to set-assignees to assign a task to them.')->required(),
                'name' => $schema->string()->description('The user\'s display name.')->required(),
            ]))->description('Matching users you share a project with. Empty when nothing matches.')->required(),
            'truncated' => $schema->boolean()->description('True when more users matched than were returned; narrow the query to see the rest.')->required(),
        ];
    }
}
