<?php

namespace App\Mcp\Concerns;

use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * The shared "waiting on" representation for the task tools: who the task is
 * waiting on and since when, or null when it is not waiting on anybody.
 */
trait ExposesWaitingOn
{
    /**
     * The waiting-on payload fragment, ready to spread into a task response.
     *
     * @return array{waiting_on: array{id: string, name: string, since: string|null}|null}
     */
    protected function waitingOnPayload(Task $task): array
    {
        $user = $task->waitingOn;

        return [
            'waiting_on' => $user === null ? null : [
                'id' => $user->public_id,
                'name' => $user->name,
                'since' => $task->waiting_since?->toIso8601String(),
            ],
        ];
    }

    /**
     * The output-schema fragment matching {@see waitingOnPayload()}.
     *
     * @return array<string, Type>
     */
    protected function waitingOnSchema(JsonSchema $schema): array
    {
        return [
            'waiting_on' => $schema->object([
                'id' => $schema->string()->description('The awaited user\'s stable user id.')->required(),
                'name' => $schema->string()->description('The awaited user\'s name.')->required(),
                'since' => $schema->string()->nullable()->description('When the wait started, as an ISO 8601 timestamp.'),
            ])->nullable()->description('The project member whose input the task is waiting on, or null when it is not waiting on anybody.'),
        ];
    }
}
