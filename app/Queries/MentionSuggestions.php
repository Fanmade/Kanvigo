<?php

namespace App\Queries;

use App\Enums\Status;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Variable;
use App\Policies\DocPolicy;
use Illuminate\Support\Facades\Gate;

/**
 * Builds the client-side autocomplete dataset for @mentions, #references and
 * [variables] in the rich-text editor: a project's members (mention targets), its
 * open tasks and docs (reference targets) and its variables. Shared by every
 * editor host (task page, doc page, project page, comments) so the shape stays
 * consistent.
 *
 * The set is filtered in the browser as the user types, so it is intentionally
 * small: members are bounded by project membership, canceled tasks — which can
 * never be a sensible link target — are excluded, and drafts are offered only to
 * the editors who can open them.
 */
class MentionSuggestions
{
    /**
     * @return array{
     *     users: list<array{id: int, name: string}>,
     *     tasks: list<array{id: int, reference: string, title: string}>,
     *     docs: list<array{id: int, reference: string, title: string}>,
     *     variables: list<array{name: string, value: string|null}>,
     *     can_create_variables: bool,
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

        return [
            'users' => array_values($users),
            'tasks' => array_values($tasks),
            'docs' => $this->docs($project),
            'variables' => $this->variables($project),
            // Whether the picker may offer to define a name it does not know.
            // Without the permission the text simply stays literal — there is no
            // request-to-create flow.
            'can_create_variables' => Gate::allows('manage-variables', $project),
        ];
    }

    /**
     * The project's variables, offered by the `[` picker. Values ride along so
     * the picker can show what each name currently stands for; an unset variable
     * carries null.
     *
     * @return list<array{name: string, value: string|null}>
     */
    private function variables(Project $project): array
    {
        return array_values($project->variables()->get()
            ->map(static fn (Variable $variable): array => [
                'name' => $variable->name,
                'value' => $variable->value,
            ])
            ->all());
    }

    /**
     * The project's docs, as reference targets. Drafts are included only when the
     * viewer may edit docs — the same rule {@see DocPolicy::view()}
     * applies — so the autocomplete never offers a doc the author cannot open.
     *
     * @return list<array{id: int, reference: string, title: string}>
     */
    private function docs(Project $project): array
    {
        $docs = $project->docs()->orderBy('doc_number');

        if (! Gate::allows('edit-doc', $project)) {
            $docs->where('is_public', true);
        }

        return array_values($docs->get()
            // The reference accessor reads $doc->project; share the one instance
            // so building references stays a single query, not one per doc.
            ->each(static fn (Doc $doc) => $doc->setRelation('project', $project))
            ->map(static fn (Doc $doc): array => [
                'id' => (int) $doc->id,
                'reference' => (string) $doc->reference,
                'title' => $doc->title,
            ])
            ->all());
    }
}
