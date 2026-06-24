<?php

namespace App\Livewire\Tasks;

use App\Actions\CancelTask;
use App\Actions\ChangeTaskStatus;
use App\Concerns\HandlesAttachments;
use App\Concerns\HasLiveUpdates;
use App\Concerns\ManagesDependencies;
use App\Concerns\ManagesParent;
use App\Concerns\ManagesTags;
use App\Concerns\PromptsParentClose;
use App\Enums\CancelReason;
use App\Enums\CascadePreference;
use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class TaskView extends Component
{
    use HandlesAttachments;
    use HasLiveUpdates;
    use ManagesDependencies;
    use ManagesParent;
    use ManagesTags;
    use PromptsParentClose;

    #[Locked]
    public string $shortName;

    #[Locked]
    public int $taskNumber;

    public bool $editing = false;

    public string $title = '';

    public string $description = '';

    public string $dueDate = '';

    public string $status = Status::Planned->value;

    public int $priority;

    /** @var array<int, int> */
    public array $assigneeIds = [];

    /**
     * A terminal status change (Done/Canceled) is held here, awaiting the
     * "also change the open subtasks?" confirmation, when the user prefers to
     * be asked.
     */
    public bool $confirmingCascade = false;

    public string $pendingStatus = '';

    /**
     * When set, the modal choice is remembered as the user's cascade preference
     * ("always" when confirmed, "never" when declined) so future closes skip it.
     */
    public bool $rememberCascadeChoice = false;

    /**
     * The status the parent held before a silent child→parent bump, kept so the
     * bump can be undone. Empty when there is nothing to undo.
     */
    public string $parentBumpUndoStatus = '';

    /**
     * Cancel-confirmation state: the reason (a {@see CancelReason} value) and an
     * optional message captured before the task is canceled.
     */
    public bool $confirmingCancel = false;

    public string $cancelReason = '';

    public string $cancelMessage = '';

    public function mount(string $short_name, int $task_number): void
    {
        $this->shortName = $short_name;
        $this->taskNumber = $task_number;

        $task = $this->task();
        $this->authorize('view', $task);

        $this->status = $task->status->value;
        $this->priority = $task->priority->value;
        $this->assigneeIds = $task->assignees->pluck('id')->all();
    }

    #[Computed]
    public function task(): Task
    {
        $project = Project::where('short_name', $this->shortName)->firstOrFail();

        $task = Task::query()
            ->with(['assignees', 'tags', 'project', 'parent', 'ancestors', 'children', 'descendants'])
            ->where('project_id', $project->id)
            ->where('task_number', $this->taskNumber)
            ->firstOrFail();

        $this->authorize('view', $task);

        return $task;
    }

    protected function attachable(): Project|Task
    {
        return $this->task();
    }

    /**
     * The endpoint the editor fetches @mention / #reference suggestions from.
     */
    #[Computed]
    public function mentionablesUrl(): string
    {
        return route('project.mentionables', $this->task()->project);
    }

    protected function dependable(): Task
    {
        return $this->task();
    }

    protected function taggable(): Task
    {
        return $this->task();
    }

    protected function forgetTaggable(): void
    {
        unset($this->task);
    }

    protected function reparentable(): Task
    {
        return $this->task();
    }

    protected function forgetReparentable(): void
    {
        unset($this->task);
    }

    /**
     * The project members available for assignment.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->task()->project->members()->orderBy('name')->get();
    }

    /**
     * Whether a subtask may still be nested under this task without exceeding the
     * configured maximum depth.
     */
    #[Computed]
    public function canAddSubtask(): bool
    {
        return $this->task()->nestingDepth() < (int) config('kanvigo.tasks.max_depth');
    }

    /**
     * Refresh the subtask list and depth gate after a subtask is created through
     * the shared creation dialog.
     */
    #[On('task-created')]
    public function refreshAfterCreate(): void
    {
        unset($this->task, $this->canAddSubtask);
    }

    /**
     * Live-updates tick: refresh the header from the latest data. Driven by the
     * task-page poll, which already skips ticks while the user is editing.
     */
    #[On('live-refresh')]
    public function liveRefresh(): void
    {
        unset($this->task);
    }

    public function updatedStatus(string $value): void
    {
        $task = $this->task();
        $this->authorize('updateStatus', $task);

        $new = Status::tryFrom($value);

        if ($new === null || $task->status === $new) {
            return;
        }

        // Closing a parent with open subtasks under the "ask" preference: hold the
        // change and let the modal decide whether to cascade.
        if ($new->isTerminal()
            && $this->cascadePreference() === CascadePreference::Ask
            && $this->openSubtaskCount() > 0
        ) {
            $this->pendingStatus = $new->value;
            $this->confirmingCascade = true;

            return;
        }

        $this->applyStatusChange($task, $new, null);
    }

    /**
     * Apply the held status change, also marking the open subtasks when confirmed.
     */
    public function confirmCascade(): void
    {
        $this->resolveCascade(true);
    }

    /**
     * Apply the held status change to this task only, leaving the subtasks open.
     */
    public function declineCascade(): void
    {
        $this->resolveCascade(false);
    }

    /**
     * Dismiss the prompt without changing anything, restoring the status control.
     */
    public function abortCascade(): void
    {
        $this->confirmingCascade = false;
        $this->pendingStatus = '';
        $this->rememberCascadeChoice = false;
        $this->status = $this->task()->status->value;
    }

    /**
     * Undo the silent bump that pulled the parent task into "In progress".
     */
    public function undoParentBump(): void
    {
        $previous = Status::tryFrom($this->parentBumpUndoStatus);
        $parent = $this->task()->parent;
        $this->parentBumpUndoStatus = '';

        if ($previous === null || $parent === null) {
            return;
        }

        $this->authorize('updateStatus', $parent);
        app(ChangeTaskStatus::class)->revert([['id' => $parent->getKey(), 'status' => $previous->value]]);

        Flux::toast(text: __('Parent task change undone.'), variant: 'success');
    }

    public function dismissParentBump(): void
    {
        $this->parentBumpUndoStatus = '';
    }

    /**
     * Open the cancel confirmation, which captures a reason and optional message.
     */
    public function confirmCancel(): void
    {
        $this->authorize('update', $this->task());

        $this->reset('cancelReason', 'cancelMessage');
        $this->resetValidation();
        $this->confirmingCancel = true;
    }

    /**
     * Dismiss the cancel confirmation without changing anything.
     */
    public function abortCancel(): void
    {
        $this->reset('confirmingCancel', 'cancelReason', 'cancelMessage');
    }

    /**
     * Cancel the task — and its open subtree — with the chosen reason and an
     * optional message, then surface how many subtasks were also canceled.
     */
    public function cancelTask(): void
    {
        $task = $this->task();
        $this->authorize('update', $task);

        $validated = $this->validate([
            'cancelReason' => ['required', new Enum(CancelReason::class)],
            'cancelMessage' => ['nullable', 'string', 'max:1000'],
        ]);

        $cascaded = app(CancelTask::class)->cancel(
            $task,
            CancelReason::from($validated['cancelReason']),
            $validated['cancelMessage'] ?: null,
        );

        $this->reset('confirmingCancel', 'cancelReason', 'cancelMessage');
        unset($this->task);

        Flux::toast(text: $cascaded > 0
            ? __('Task and :count subtask(s) canceled.', ['count' => $cascaded])
            : __('Task canceled.'), variant: 'success');
    }

    /**
     * Reopen the canceled task, returning it to Planned.
     */
    public function reopenTask(): void
    {
        $task = $this->task();
        $this->authorize('update', $task);

        app(CancelTask::class)->reopen($task);

        unset($this->task);

        Flux::toast(text: __('Task reopened.'), variant: 'success');
    }

    /**
     * The number of open (non-terminal) tasks anywhere under this task.
     */
    #[Computed]
    public function openSubtaskCount(): int
    {
        return $this->task()->descendants()->get()
            ->reject(static fn (Task $task): bool => $task->status->isTerminal())
            ->count();
    }

    /**
     * The status currently awaiting cascade confirmation, if any.
     */
    #[Computed]
    public function pendingStatusEnum(): ?Status
    {
        return Status::tryFrom($this->pendingStatus);
    }

    /**
     * Resolve the held cascade prompt and apply the change with the given choice.
     */
    private function resolveCascade(bool $cascadeToChildren): void
    {
        $new = Status::tryFrom($this->pendingStatus);
        $remember = $this->rememberCascadeChoice;
        $this->confirmingCascade = false;
        $this->pendingStatus = '';
        $this->rememberCascadeChoice = false;

        if ($new === null) {
            return;
        }

        $task = $this->task();
        $this->authorize('updateStatus', $task);

        if ($remember) {
            Auth::user()?->setPreference(
                ChangeTaskStatus::PREFERENCE_KEY,
                ($cascadeToChildren ? CascadePreference::Always : CascadePreference::Never)->value,
            );
        }

        $this->applyStatusChange($task, $new, $cascadeToChildren);
    }

    /**
     * Run the status change through the shared cascade action and surface the
     * outcome (a success toast, plus an undo affordance for a parent bump).
     */
    private function applyStatusChange(Task $task, Status $new, ?bool $cascadeToChildren): void
    {
        $result = app(ChangeTaskStatus::class)->handle($task, $new, $cascadeToChildren);

        unset($this->task);
        $this->status = $new->value;
        $this->parentBumpUndoStatus = $result->parentBumped ? (string) $result->parentPreviousStatus : '';

        $this->maybePromptParentClose($result, $new);

        Flux::toast(
            text: $result->parentBumped
                ? __('Status updated. Parent task moved to In progress.')
                : __('Status updated.'),
            variant: 'success',
        );
    }

    /**
     * The viewer's parent→children cascade preference, defaulting to "ask".
     */
    private function cascadePreference(): CascadePreference
    {
        $value = Auth::user()?->preference(ChangeTaskStatus::PREFERENCE_KEY, CascadePreference::Ask->value);

        return CascadePreference::tryFrom((string) $value) ?? CascadePreference::Ask;
    }

    public function updatedPriority(string|int $value): void
    {
        $task = $this->task();
        $this->authorize('update', $task);

        $new = Priority::tryFrom((int) $value);

        if ($new === null || $task->priority === $new) {
            return;
        }

        $old = $task->priority;
        $task->priority = $new;
        $task->save();
        $task->recordActivity('priority_changed', 'priority', (string) $old->value, (string) $new->value);

        unset($this->task);
        Flux::toast(text: __('Priority updated.'), variant: 'success');
    }

    public function updatedAssigneeIds(): void
    {
        $task = $this->task();
        $this->authorize('update', $task);

        $changes = $task->assignees()->sync($this->assigneeIds);

        // Assigning a user automatically subscribes them to updates.
        if ($changes['attached'] !== []) {
            $task->subscribers()->syncWithoutDetaching($changes['attached']);
        }

        $task->recordAssigneeChange($changes['attached'], $changes['detached']);

        unset($this->task);
    }

    /**
     * One-click self-assignment: add the current user to the assignees and run
     * the standard sync, so the auto-subscribe and activity logging still apply.
     */
    public function assignToMe(): void
    {
        $userId = Auth::id();

        if (in_array($userId, $this->assigneeIds, true)) {
            return;
        }

        $this->assigneeIds[] = (int) $userId;
        $this->updatedAssigneeIds();
    }

    public function edit(): void
    {
        $task = $this->task();
        $this->authorize('update', $task);

        $this->title = $task->title;
        $this->description = (string) $task->description;
        $this->dueDate = $task->due_date?->format('Y-m-d') ?? '';
        $this->editing = true;
    }

    public function save(): void
    {
        $task = $this->task();
        $this->authorize('update', $task);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'dueDate' => ['nullable', 'date'],
        ]);

        $task->update([
            'title' => $validated['title'],
            'description' => $validated['description'],
            'due_date' => $validated['dueDate'] ?: null,
        ]);

        $this->editing = false;
        unset($this->task);

        Flux::toast(text: __('Task updated.'), variant: 'success');
    }
}
