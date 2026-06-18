<?php

namespace App\Livewire\Notifications;

use Illuminate\Support\Facades\Auth;
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

        $notifications = $user->notifications()->get();
        $key = static fn ($notification) => ($notification->data['subject_type'] ?? '').':'.($notification->data['subject_id'] ?? '');
        $total = $notifications
            ->groupBy($key)
            ->map(fn ($notifications) => $notifications->count())
            ->all();
        $unread = $notifications
            ->whereNull('read_at')
            ->groupBy($key)
            ->map(fn ($notifications) => $notifications->count())
            ->all();
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

        foreach ($user->subscribedStories()->with('project')->get() as $story) {
            $rows[] = [
                'group' => __('Stories'),
                'type' => 'story',
                'id' => $story->id,
                'label' => $story->reference.' · '.$story->title,
                'url' => route('story.show', ['short_name' => $story->project->short_name, 'story_number' => $story->story_number]),
                'total' => $total['Story:'.$story->id] ?? 0,
                'unread' => $unread['Story:'.$story->id] ?? 0,
            ];
        }

        foreach ($user->subscribedTasks()->with('story.project')->get() as $task) {
            $rows[] = [
                'group' => __('Tasks'),
                'type' => 'task',
                'id' => $task->id,
                'label' => $task->reference.' · '.$task->title,
                'url' => route('task.show', ['short_name' => $task->story->project->short_name, 'story_number' => $task->story->story_number, 'task_number' => $task->task_number]),
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
            'story' => $user->subscribedStories()->whereKey($id)->first(),
            'task' => $user->subscribedTasks()->whereKey($id)->first(),
            default => null,
        };

        $model?->unsubscribe($user);

        unset($this->rows);
    }
}
