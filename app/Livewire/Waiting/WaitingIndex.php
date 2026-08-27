<?php

namespace App\Livewire\Waiting;

use App\Actions\PostComment;
use App\Concerns\HandlesAttachments;
use App\Enums\WaitingScope;
use App\Models\Project;
use App\Models\Task;
use App\Queries\WaitingTasks;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The cross-project "Waiting on" page: everything held up on a person, from both
 * sides. "Waiting on me" is the answer queue — the tasks other people are
 * blocked on until this user replies; "I'm waiting on" is the asker's nag list.
 *
 * Rows carry enough context to answer in place (project, title, who asked, how
 * long, the full description) and a quick reply, so the common case never needs
 * the task page. The reply composer belongs to the page rather than to each row:
 * only one answer is written at a time, and a per-row child component would cost
 * a query per row on a list that is meant to stay flat as it grows.
 *
 * @property-read WaitingScope $scope
 * @property-read Collection<int|string, Collection<int, Task>> $groups
 * @property-read int $onMeCount
 * @property-read int $byMeCount
 * @property-read array<int, bool> $commentableProjectIds
 */
#[Title('Waiting on')]
class WaitingIndex extends Component
{
    use HandlesAttachments;

    /**
     * The active tab, a {@see WaitingScope} value. Lives in the URL so a link can
     * point at either side.
     */
    #[Url]
    public string $tab = 'on-me';

    /**
     * The id of the task whose reply composer is open, or null when none is.
     */
    public ?int $replyingTo = null;

    public string $replyBody = '';

    /**
     * The scope the active tab selects, falling back to the answer queue.
     */
    #[Computed]
    public function scope(): WaitingScope
    {
        return WaitingScope::tryFrom($this->tab) ?? WaitingScope::OnMe;
    }

    /**
     * The current tab's tasks grouped by their project, projects in title order,
     * tasks oldest-wait-first within each.
     *
     * @return Collection<int|string, Collection<int, Task>>
     */
    #[Computed]
    public function groups(): Collection
    {
        return app(WaitingTasks::class)->handle(Auth::user(), $this->scope)
            ->get()
            ->toBase()
            ->groupBy(static fn (Task $task): int => $task->project_id)
            ->sortBy(static fn (Collection $tasks): string => $tasks->first()->project->title);
    }

    /**
     * The ids of the listed projects the viewer may comment in, resolved once for
     * the whole page — the permission check is per project, not per task, and the
     * list repeats the same handful of projects across many rows.
     *
     * @return array<int, bool>
     */
    #[Computed]
    public function commentableProjectIds(): array
    {
        $user = Auth::user();

        return $this->groups
            ->mapWithKeys(static function (Collection $tasks) use ($user): array {
                $project = $tasks->first()->project;

                return [$project->getKey() => $user->can('create-comment', $project)];
            })
            ->all();
    }

    /**
     * How many tasks are waiting on this user to answer — the tab's own count,
     * mirroring the sidebar badge.
     */
    #[Computed]
    public function onMeCount(): int
    {
        return app(WaitingTasks::class)->handle(Auth::user(), WaitingScope::OnMe)->count();
    }

    /**
     * How many tasks this user is waiting on somebody else for.
     */
    #[Computed]
    public function byMeCount(): int
    {
        return app(WaitingTasks::class)->handle(Auth::user(), WaitingScope::ByMe)->count();
    }

    /**
     * Inline images pasted into the reply editor attach to the task being
     * answered, exactly as they would on its own page.
     */
    protected function attachable(): Project|Task
    {
        return $this->replyTarget();
    }

    /**
     * The endpoint the reply editor fetches @mention / #reference suggestions
     * from — the project of the task being answered.
     */
    #[Computed]
    public function mentionablesUrl(): ?string
    {
        return $this->replyingTo === null
            ? null
            : route('project.mentionables', $this->replyTarget()->project);
    }

    /**
     * Open the reply composer on one task, closing whichever was open before.
     */
    public function startReply(int $taskId): void
    {
        $this->replyingTo = $taskId;
        $this->replyBody = '';
        $this->resetValidation();

        // Authorize eagerly so an id that isn't the viewer's business never even
        // opens a composer.
        $this->replyTarget();

        unset($this->mentionablesUrl);
    }

    public function cancelReply(): void
    {
        $this->reset('replyingTo', 'replyBody');
        $this->resetValidation();
        unset($this->mentionablesUrl);
    }

    /**
     * Post the reply as an ordinary task comment. The Phase 1 auto-clear ends the
     * wait it answers, so the row leaves the list on the next render.
     */
    public function reply(): void
    {
        $task = $this->replyTarget();
        $this->authorize('create-comment', $task->project);

        $validated = $this->validate([
            'replyBody' => ['required', 'string', 'max:5000'],
        ]);

        app(PostComment::class)->handle($task, $validated['replyBody']);

        $this->reset('replyingTo', 'replyBody');
        unset($this->groups, $this->commentableProjectIds, $this->onMeCount, $this->byMeCount, $this->mentionablesUrl);

        Flux::toast(text: __('Reply posted.'), variant: 'success');
    }

    /**
     * The task the composer is open on, re-authorized on every read so a tampered
     * id cannot reach another project's task.
     */
    private function replyTarget(): Task
    {
        abort_if($this->replyingTo === null, 404);

        $task = Task::with('project')->findOrFail($this->replyingTo);

        $this->authorize('view', $task);

        return $task;
    }

    public function render(): View
    {
        return view('livewire.waiting.waiting-index');
    }
}
