<?php

namespace App\Mcp\Concerns;

use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * The shared task representation for the task write tools ({@see CreateTaskTool},
 * {@see UpdateTaskTool}). Both return the same core fields; each spreads its own
 * extras on top (the create tool adds `parent`, the update tool adds the
 * `cancel_reason`/`cancel_message` lifecycle fields).
 */
trait PresentsTasks
{
    /**
     * The core task write payload, shared by the create and update tools.
     *
     * @return array<string, mixed>
     */
    protected function taskWritePayload(Task $task): array
    {
        return [
            'reference' => $task->reference,
            'title' => $task->title,
            'description' => $task->description,
            'priority' => $task->priority->name,
            'due_date' => $task->due_date?->format('Y-m-d'),
            'status' => $task->status->value,
            'tags' => $task->tags()->pluck('name')->all(),
        ];
    }

    /**
     * The core output-schema fields matching {@see taskWritePayload()}.
     *
     * @return array<string, Type>
     */
    protected function taskWriteSchema(JsonSchema $schema): array
    {
        return [
            'reference' => $schema->string()->description('The task reference, e.g. "PROJ-42".')->required(),
            'title' => $schema->string()->description('The task title.')->required(),
            'description' => $schema->string()->description('The task description as HTML; may be null.'),
            'priority' => $schema->string()->description('The task priority: Lowest, Low, Medium, High or Highest.')->required(),
            'due_date' => $schema->string()->description('The task due date in "YYYY-MM-DD" format; may be null.'),
            'status' => $schema->string()->description('The task status.')->required(),
            'tags' => $schema->array()->items($schema->string())->description('The tag names applied to the task.')->required(),
        ];
    }
}
