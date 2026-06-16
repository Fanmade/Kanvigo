<?php

namespace App\Notifications;

use App\Models\Activity;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ItemActivity extends Notification
{
    use Queueable;

    public function __construct(public Activity $activity) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $subject = $this->activity->subject;

        return [
            'activity_id' => $this->activity->id,
            'action' => $this->activity->action,
            'subject_type' => class_basename($this->activity->subject_type),
            'subject_id' => $this->activity->subject_id,
            'reference' => $subject->reference ?? $subject->short_name ?? null,
            'title' => $subject->title ?? null,
            'actor' => $this->activity->user?->name,
            'url' => $this->urlFor($subject),
        ];
    }

    private function urlFor(mixed $subject): ?string
    {
        return match (true) {
            $subject instanceof Task => route('task.show', [
                'short_name' => $subject->story->project->short_name,
                'story_number' => $subject->story->story_number,
                'task_number' => $subject->task_number,
            ]),
            $subject instanceof Story => route('story.show', [
                'short_name' => $subject->project->short_name,
                'story_number' => $subject->story_number,
            ]),
            $subject instanceof Project => route('project.show', ['short_name' => $subject->short_name]),
            default => null,
        };
    }
}
