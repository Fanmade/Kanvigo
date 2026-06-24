<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells a user they were @mentioned in a task/project description or a comment.
 *
 * Unlike {@see ItemActivity} (which fans out to every subscriber of an item),
 * this is delivered only to the mentioned user. It mirrors ItemActivity's
 * database payload so the notifications menu renders both the same way.
 */
class UserMentioned extends Notification
{
    use Queueable;

    public function __construct(public Project|Task $subject, public ?User $actor) {}

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
        return [
            'action' => 'mentioned',
            'subject_type' => class_basename($this->subject),
            'subject_id' => $this->subject->id,
            'reference' => $this->subject instanceof Task ? $this->subject->reference : $this->subject->short_name,
            'title' => $this->subject->title,
            'actor' => $this->actor?->name,
            'url' => $this->subject instanceof Task
                ? route('task.show', [
                    'short_name' => $this->subject->project->short_name,
                    'task_number' => $this->subject->task_number,
                ])
                : route('project.show', ['short_name' => $this->subject->short_name]),
        ];
    }
}
