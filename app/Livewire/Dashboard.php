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
     * Maximum number of actionable tasks rendered in the "My tasks" list.
     */
    private const ACTIVE_TASKS_LIMIT = 50;

    /**
     * The user's actionable tasks: in progress first, then to-do.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function activeTasks(): Collection
    {
        return Auth::user()->assignedTasks()
            ->whereIn('tasks.status', [Status::InProgress, Status::ToDo])
            ->join('stories', 'stories.id', '=', 'tasks.story_id')
            ->join('projects', 'projects.id', '=', 'stories.project_id')
            ->orderByRaw('case when tasks.status = ? then 0 else 1 end', [Status::InProgress->value])
            ->orderBy('projects.short_name')
            ->orderBy('stories.story_number')
            ->orderBy('tasks.task_number')
            ->with('story.project')
            ->select('tasks.*')
            ->limit(self::ACTIVE_TASKS_LIMIT)
            ->get();
    }

    /**
     * Per-status counts of the user's assigned tasks.
     *
     * @return Collection<int, array{status: Status, count: int}>
     */
    #[Computed]
    public function statusCounts(): Collection
    {
        $counts = Auth::user()->assignedTasks()
            ->toBase()
            ->selectRaw('tasks.status as status, count(*) as aggregate')
            ->groupBy('tasks.status')
            ->pluck('aggregate', 'status');

        return collect(Status::columns())->map(static fn (Status $status) => [
            'status' => $status,
            'count' => (int) ($counts[$status->value] ?? 0),
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
        $dateExpression = $this->dateExpression();

        $counts = Activity::query()
            ->where('user_id', Auth::id())
            ->where('action', 'status_changed')
            ->where('new_value', Status::Done->value)
            ->where('created_at', '>=', $start)
            ->toBase()
            ->selectRaw("{$dateExpression} as day, count(*) as aggregate")
            ->groupByRaw($dateExpression)
            ->pluck('aggregate', 'day');

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

    /**
     * A driver-portable SQL expression that truncates `created_at` to its date.
     *
     * PostgreSQL has no `date()` function and instead casts with `::date`, while
     * SQLite and MySQL/MariaDB all expose `date()`. Both forms return a
     * `YYYY-MM-DD` string when fetched, so the grouped keys line up with
     * Carbon's `toDateString()` regardless of the active driver.
     *
     * @return literal-string
     */
    private function dateExpression(): string
    {
        $driver = (new Activity)->getConnection()->getDriverName();

        return $driver === 'pgsql' ? 'created_at::date' : 'date(created_at)';
    }
}
