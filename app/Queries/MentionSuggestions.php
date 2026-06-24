<?php

namespace App\Queries;

use App\Enums\Status;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

/**
 * Builds the client-side autocomplete dataset for @mentions and #references in
 * the rich-text editor: a project's members (mention targets) and its open tasks
 * (reference targets). Shared by every editor host (task page, project page,
 * comments) so the shape stays consistent.
 *
 * The set is filtered in the browser as the user types, so it is intentionally
 * small: members are bounded by project membership, and canceled tasks — which
 * can never be a sensible link target — are excluded.
 */
class MentionSuggestions
{
    /**
     * @return array{
     *     users: list<array{id: int, name: string}>,
     *     tasks: list<array{id: int, reference: string, title: string}>,
     * }
     */
    public function handle(Project $project): array
    {
        $users = $project->members()
            ->orderBy('name')
            ->get(['users.id', 'users.name'])
            ->map(static fn (User $user): array => ['id' => (int) $user->id, 'name' => (string) $user->name])
            ->all();

        $tasks = $project->tasks()
            ->where('status', '!=', Status::Canceled->value)
            ->orderByDesc('task_number')
            ->get()
            // The reference accessor reads $task->project; share the one instance
            // so building references stays a single query, not one per task.
            ->each(static fn (Task $task) => $task->setRelation('project', $project))
            ->map(static fn (Task $task): array => [
                'id' => (int) $task->id,
                'reference' => (string) $task->reference,
                'title' => $task->title,
            ])
            ->all();

        return ['users' => array_values($users), 'tasks' => array_values($tasks)];
    }
}
