<?php

namespace App\Console\Commands;

use App\Contracts\UsesVariables;
use App\Models\Comment;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

#[Signature('variables:reindex {--project= : Limit the rebuild to one project, by short name}')]
#[Description('Rebuild the variable usage index from the content it is derived from.')]
class ReindexVariableUsages extends Command
{
    /**
     * The rebuild path for derived state maintained by a queue: run this after a
     * queue outage, a batch of failed jobs, or a bulk import through the API.
     *
     * The index is reconciled item by item rather than truncated and refilled, so
     * a rebuild is safe to run at any time — it never leaves a window in which
     * usages are missing.
     */
    public function handle(): int
    {
        $project = $this->targetProject();

        if ($project === false) {
            return self::FAILURE;
        }

        $items = 0;

        foreach ($this->sources($project) as $query) {
            $query->chunkById(100, function (Collection $chunk) use (&$items): void {
                foreach ($chunk as $item) {
                    /** @var UsesVariables&Model $item */
                    $item->syncVariableUsages();
                    $items++;
                }
            });
        }

        $scope = $project instanceof Project ? " for {$project->short_name}" : '';
        $this->info("Reindexed variable usages{$scope} across {$items} item(s).");

        return self::SUCCESS;
    }

    /**
     * The project named by --project, null for "every project", or false when the
     * short name matches nothing (the caller reports the failure).
     */
    private function targetProject(): Project|null|false
    {
        $shortName = $this->option('project');

        if ($shortName === null) {
            return null;
        }

        $project = Project::query()->where('short_name', mb_strtoupper((string) $shortName))->first();

        if ($project === null) {
            $this->error("No project with the short name \"{$shortName}\".");

            return false;
        }

        return $project;
    }

    /**
     * The queries covering every item whose content can carry a usage, scoped to
     * one project when asked. Notes are absent by design: a note is projectless,
     * so it has no variable namespace.
     *
     * @return list<Builder<covariant Model>>
     */
    private function sources(?Project $project): array
    {
        $tasks = Task::query();
        $docs = Doc::query();
        $projects = Project::query();
        $comments = Comment::query();

        if ($project instanceof Project) {
            $projectId = $project->getKey();

            $tasks->where('project_id', $projectId);
            $docs->where('project_id', $projectId);
            $projects->whereKey($projectId);

            // A comment belongs to the project through whatever it was written on.
            $comments->where(static function ($query) use ($project, $projectId): void {
                $query
                    ->whereMorphedTo('commentable', $project)
                    ->orWhere(static fn ($commented) => $commented
                        ->where('commentable_type', (new Task)->getMorphClass())
                        ->whereIn('commentable_id', Task::query()->where('project_id', $projectId)->select('id')))
                    ->orWhere(static fn ($commented) => $commented
                        ->where('commentable_type', (new Doc)->getMorphClass())
                        ->whereIn('commentable_id', Doc::query()->where('project_id', $projectId)->select('id')));
            });
        }

        return [$projects, $tasks, $docs, $comments];
    }
}
