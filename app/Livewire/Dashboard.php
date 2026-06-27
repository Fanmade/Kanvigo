<?php

namespace App\Livewire;

use App\Concerns\ManagesNotes;
use App\Enums\Status;
use App\Models\Activity;
use App\Models\Note;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    use ManagesNotes;

    /**
     * Maximum number of actionable tasks rendered in the "My tasks" list.
     */
    private const ACTIVE_TASKS_LIMIT = 50;

    /**
     * Maximum number of notes rendered in the Notes panel.
     */
    private const NOTES_LIMIT = 50;

    /**
     * The user's actionable tasks across their projects, in progress first then
     * to-do. Includes tasks assigned to the user plus unassigned to-do tasks
     * they can pick up; tasks assigned to other people are excluded.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function activeTasks(): Collection
    {
        $userId = Auth::id();
        $projectIds = Auth::user()->projects()->pluck('projects.id');

        return Task::query()
            ->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->whereIn('tasks.project_id', $projectIds)
            ->whereIn('tasks.status', [Status::InProgress, Status::ToDo])
            ->where(static function (Builder $query) use ($userId): void {
                $query->whereHas('assignees', static fn (Builder $assignees): Builder => $assignees->whereKey($userId))
                    ->orWhere(static fn (Builder $unassigned): Builder => $unassigned
                        ->where('tasks.status', Status::ToDo)
                        ->whereDoesntHave('assignees'));
            })
            ->orderByRaw('case when tasks.status = ? then 0 else 1 end', [Status::InProgress->value])
            ->orderBy('projects.short_name')
            ->orderBy('tasks.task_number')
            ->with('project')
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
     * The user's notes, newest first. Empty-title drafts (left behind by an
     * abandoned note dialog) are hidden.
     *
     * @return EloquentCollection<int, Note>
     */
    #[Computed]
    public function notes(): EloquentCollection
    {
        return Auth::user()->notes()
            ->where('title', '!=', '')
            ->with(['project', 'convertedTask.project'])
            ->orderByDesc('is_pinned')
            ->latest('updated_at')
            ->limit(self::NOTES_LIMIT)
            ->get();
    }

    protected function forgetNotes(): void
    {
        unset($this->notes);
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
     * Capped to a handful of evenly spaced, "nice" steps (1/2/5 × 10ⁿ) so the
     * axis stays readable instead of printing a label for every single count.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function progressTicks(): array
    {
        $max = max(1, (int) collect($this->progress())->max('count'));
        $step = $this->niceTickStep($max);

        $ticks = [];
        for ($value = 0; $value <= $max; $value += $step) {
            $ticks[] = $value;
        }

        return $ticks;
    }

    /**
     * The smallest "nice" step (1, 2 or 5 × 10ⁿ) that splits the range into no
     * more than roughly {@see $targetTicks} intervals.
     */
    private function niceTickStep(int $max, int $targetTicks = 5): int
    {
        $rawStep = max(1, (int) ceil($max / $targetTicks));
        $magnitude = 10 ** (int) floor(log10($rawStep));

        foreach ([1, 2, 5] as $factor) {
            if ($factor * $magnitude >= $rawStep) {
                return $factor * $magnitude;
            }
        }

        return 10 * $magnitude;
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
