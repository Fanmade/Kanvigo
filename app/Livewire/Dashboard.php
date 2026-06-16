<?php

namespace App\Livewire;

use App\Enums\Status;
use App\Models\Activity;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    /**
     * All tasks assigned to the current user (used for the status statistics).
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function assignedTasks(): Collection
    {
        return Auth::user()->assignedTasks()->with(['story.project'])->get();
    }

    /**
     * The user's actionable tasks: in progress first, then to-do.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function activeTasks(): Collection
    {
        return $this->assignedTasks()
            ->whereIn('status', [Status::InProgress, Status::ToDo])
            ->sortBy(static fn (Task $task) => sprintf(
                '%d-%s-%05d-%05d',
                $task->status === Status::InProgress ? 0 : 1,
                $task->story->project->short_name,
                $task->story->story_number,
                $task->task_number,
            ))
            ->values();
    }

    /**
     * Per-status counts of the user's assigned tasks.
     *
     * @return Collection<int, array{status: Status, count: int}>
     */
    #[Computed]
    public function statusCounts(): Collection
    {
        $tasks = $this->assignedTasks();

        return collect(Status::columns())->map(static fn (Status $status) => [
            'status' => $status,
            'count' => $tasks->where('status', $status)->count(),
        ]);
    }

    #[Computed]
    public function projectCount(): int
    {
        return Auth::user()->projects()->count();
    }

    /**
     * Tasks the user completed per day over the last 14 days.
     *
     * @return array<int, array{date: string, label: string, count: int}>
     */
    #[Computed]
    public function progress(): array
    {
        $start = now()->subDays(13)->startOfDay();

        $counts = Activity::query()
            ->where('user_id', Auth::id())
            ->where('action', 'status_changed')
            ->where('new_value', Status::Done->value)
            ->where('created_at', '>=', $start)
            ->get()
            ->groupBy(static fn (Activity $activity) => $activity->created_at->toDateString())
            ->map->count();

        return collect(range(0, 13))->map(static function (int $offset) use ($start, $counts) {
            $date = $start->copy()->addDays($offset);

            return [
                'date' => $date->toDateString(),
                'label' => $date->format('d.m'),
                'count' => (int) ($counts[$date->toDateString()] ?? 0),
            ];
        })->all();
    }

    /**
     * Integer tick values for the progress chart's Y axis.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function progressTicks(): array
    {
        return range(0, max(1, (int) collect($this->progress())->max('count')));
    }
}
