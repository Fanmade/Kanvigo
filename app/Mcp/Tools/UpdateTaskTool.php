<?php

namespace App\Mcp\Tools;

use App\Actions\CancelTask;
use App\Actions\ChangeTaskStatus;
use App\Enums\CancelReason;
use App\Enums\Priority;
use App\Enums\Status;
use App\Mcp\Concerns\NormalizesPlainText;
use App\Mcp\Concerns\RecordsTagChanges;
use App\Mcp\Concerns\RequiresWriteAccess;
use App\Support\ReferenceResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Updates a task\'s title, description, priority, status and/or tags, identified by its reference (e.g. "PROJ-42"). Can also cancel the task with a reason (cancel_reason, optionally cancel_message) — which cancels its open subtasks too — or reopen a canceled task (reopen=true). Status, cancellation and tag changes are recorded in the activity log. Requires a write-access token; the user must be a member of the project.')]
class UpdateTaskTool extends Tool
{
    use NormalizesPlainText;
    use RecordsTagChanges;
    use RequiresWriteAccess;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        if ($denied = $this->denyWithoutWriteAccess($request)) {
            return $denied;
        }

        $workingStatuses = array_map(static fn (Status $status): string => $status->value, Status::columns());

        $validated = $request->validate([
            'reference' => ['required', 'string'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', Rule::in(Priority::names())],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::in($workingStatuses)],
            'cancel_reason' => ['nullable', Rule::in(CancelReason::names())],
            'cancel_message' => ['nullable', 'string', 'max:1000'],
            'reopen' => ['nullable', 'boolean'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
        ], [
            'reference.required' => 'You must provide the task reference (e.g. "PROJ-42").',
            'priority' => 'The priority must be one of: '.implode(', ', Priority::names()).'.',
            'due_date' => 'The due date must be a calendar date in "YYYY-MM-DD" format. Pass null to clear it.',
            'status' => 'The status must be one of "'.implode('", "', $workingStatuses).'". To cancel a task, pass cancel_reason instead; to reopen a canceled task, pass reopen=true.',
            'cancel_reason' => 'The cancel reason must be one of: '.implode(', ', CancelReason::names()).'.',
        ]);

        $task = ReferenceResolver::task($validated['reference']);

        if ($task === null || ! $request->user()->can('update', $task)) {
            return Response::error('No task with reference "'.$validated['reference'].'" exists, or you do not have access to it. References look like "PROJ-42".');
        }

        $updates = [];

        if ($request->has('title')) {
            $updates['title'] = $this->decodePlainText($validated['title']);
        }

        if ($request->has('description')) {
            $updates['description'] = $validated['description'];
        }

        if ($request->has('priority') && isset($validated['priority'])) {
            $updates['priority'] = Priority::fromName($validated['priority']);
        }

        if ($request->has('due_date')) {
            $updates['due_date'] = $validated['due_date'];
        }

        $statusProvided = $request->has('status') && isset($validated['status']);
        $cancelProvided = $request->has('cancel_reason') && isset($validated['cancel_reason']);
        $reopenRequested = $request->has('reopen') && (bool) $validated['reopen'];
        $messageProvided = $request->has('cancel_message') && isset($validated['cancel_message']);
        $tagsProvided = $request->has('tags');

        // status, cancel_reason and reopen are mutually exclusive lifecycle moves.
        if ((int) $statusProvided + (int) $cancelProvided + (int) $reopenRequested > 1) {
            return Response::error('Provide only one of status, cancel_reason or reopen in a single update.');
        }

        if ($messageProvided && ! $cancelProvided) {
            return Response::error('cancel_message can only be used together with cancel_reason.');
        }

        if ($updates === [] && ! $statusProvided && ! $cancelProvided && ! $reopenRequested && ! $tagsProvided) {
            return Response::error('Provide a title, description, priority, due date, status, tags, a cancel_reason or reopen to update.');
        }

        if ($statusProvided && $task->isCanceled()) {
            return Response::error('This task is canceled. Pass reopen=true to reopen it before changing its status.');
        }

        if ($updates !== []) {
            $task->update($updates);
        }

        if ($cancelProvided) {
            // Cancels the task and its open subtree, recording history and notifying
            // subscribers exactly like the UI.
            app(CancelTask::class)->cancel(
                $task,
                CancelReason::fromName($validated['cancel_reason']),
                $messageProvided ? $validated['cancel_message'] : null,
            );
        } elseif ($reopenRequested) {
            app(CancelTask::class)->reopen($task);
        } elseif ($statusProvided) {
            $new = Status::from($validated['status']);

            if ($task->status !== $new) {
                // Routed through the shared action so a status change made over MCP
                // runs the same parent/child cascade as the UI.
                app(ChangeTaskStatus::class)->handle($task, $new);
            }
        }

        if ($tagsProvided) {
            $this->recordTagSync($task, $task->syncTags($validated['tags'] ?? []));
        }

        $task->refresh();

        return Response::structured([
            'reference' => $task->reference,
            'title' => $task->title,
            'description' => $task->description,
            'priority' => $task->priority->name,
            'due_date' => $task->due_date?->format('Y-m-d'),
            'status' => $task->status->value,
            'cancel_reason' => $task->cancel_reason?->name,
            'cancel_message' => $task->cancel_message,
            'tags' => $task->tags()->pluck('name')->all(),
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
                ->description('The reference of the task to update (e.g. "PROJ-42").')
                ->required(),

            'title' => $schema->string()
                ->description('New title for the task.'),

            'description' => $schema->string()
                ->description('New description for the task, as HTML (sanitized to a small allow-list; unsupported tags are dropped).'),

            'priority' => $schema->string()
                ->enum(Priority::names())
                ->description('New priority: one of Lowest, Low, Medium, High, Highest.'),

            'due_date' => $schema->string()
                ->description('New due date in "YYYY-MM-DD" format. Pass null to clear it.'),

            'status' => $schema->string()
                ->enum(array_map(static fn (Status $status): string => $status->value, Status::columns()))
                ->description('New working status for the task (Planned, ToDo, In progress or Done). To cancel a task use cancel_reason; to reopen a canceled task use reopen.'),

            'cancel_reason' => $schema->string()
                ->enum(CancelReason::names())
                ->description('Cancel the task (and its open subtasks) with this reason: one of WontFix, Duplicate or Deprecated. Recorded in history and notified like a UI cancellation.'),

            'cancel_message' => $schema->string()
                ->description('An optional note explaining the cancellation. Only used together with cancel_reason.'),

            'reopen' => $schema->boolean()
                ->description('Set to true to reopen a canceled task, returning it to Planned and clearing its cancellation.'),

            'tags' => $schema->array()
                ->items($schema->string())
                ->description('The complete set of tags for the task, as an array of tag names (e.g. ["UI/UX", "bug"]). Replaces the existing tags; pass [] to clear them. Tags that do not exist yet are created.'),
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
            'reference' => $schema->string()->description('The task reference, e.g. "PROJ-42".')->required(),
            'title' => $schema->string()->description('The updated task title.')->required(),
            'description' => $schema->string()->description('The updated task description as HTML; may be null.'),
            'priority' => $schema->string()->description('The task priority: Lowest, Low, Medium, High or Highest.')->required(),
            'due_date' => $schema->string()->description('The task due date in "YYYY-MM-DD" format; may be null.'),
            'status' => $schema->string()->description('The task status.')->required(),
            'cancel_reason' => $schema->string()->description('The cancellation reason (WontFix, Duplicate or Deprecated) when the task is canceled; null otherwise.'),
            'cancel_message' => $schema->string()->description('The optional note left when the task was canceled; null otherwise.'),
            'tags' => $schema->array()->items($schema->string())->description('The tag names applied to the task.')->required(),
        ];
    }
}
