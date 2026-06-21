<?php

namespace App\Livewire\Notifications;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Notifications')]
class ManageNotifications extends Component
{
    /**
     * The user's subscriptions with per-item notification counts.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function rows(): array
    {
        $user = Auth::user();

        // Aggregate per-subject totals in the database rather than loading every
        // notification row into memory. Laravel's `data->key` JSON selectors are
        // translated to the driver-appropriate, unquoted extraction (SQLite,
        // MySQL/MariaDB and PostgreSQL alike), so the grouped keys match the
        // `subject_type` / `subject_id` written by the notification.
        $counts = $user->notifications()
            ->toBase()
            ->groupBy('data->subject_type', 'data->subject_id')
            ->get([
                'data->subject_type as subject_type',
                'data->subject_id as subject_id',
                DB::raw('count(*) as total'),
                DB::raw('sum(case when read_at is null then 1 else 0 end) as unread'),
            ]);

        $total = [];
        $unread = [];

        foreach ($counts as $row) {
            $subjectKey = $row->subject_type.':'.$row->subject_id;
            $total[$subjectKey] = (int) $row->total;
            $unread[$subjectKey] = (int) $row->unread;
        }

        $rows = [];

        foreach ($user->subscribedProjects()->orderBy('title')->get() as $project) {
            $rows[] = [
                'group' => __('Projects'),
                'type' => 'project',
                'id' => $project->id,
                'label' => $project->short_name.' · '.$project->title,
                'url' => route('project.show', ['short_name' => $project->short_name]),
                'total' => $total['Project:'.$project->id] ?? 0,
                'unread' => $unread['Project:'.$project->id] ?? 0,
            ];
        }

        foreach ($user->subscribedTasks()->with('project')->get() as $task) {
            $rows[] = [
                'group' => __('Tasks'),
                'type' => 'task',
                'id' => $task->id,
                'label' => $task->reference.' · '.$task->title,
                'url' => route('task.show', ['short_name' => $task->project->short_name, 'task_number' => $task->task_number]),
                'total' => $total['Task:'.$task->id] ?? 0,
                'unread' => $unread['Task:'.$task->id] ?? 0,
            ];
        }

        return $rows;
    }

    public function unsubscribe(string $type, int $id): void
    {
        $user = Auth::user();

        // Scope the lookup to the caller's own subscriptions so a tampered id can
        // never resolve — and act on — an item the user isn't subscribed to.
        $model = match ($type) {
            'project' => $user->subscribedProjects()->whereKey($id)->first(),
            'task' => $user->subscribedTasks()->whereKey($id)->first(),
            default => null,
        };

        $model?->unsubscribe($user);

        unset($this->rows);
    }
}
