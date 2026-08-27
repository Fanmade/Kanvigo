<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * The nudge for "waiting on" requests that have gone unanswered past their
 * project's threshold: one notification for the whole project's backlog rather
 * than one per task, so a week of silence costs the awaited person a single
 * line in their inbox.
 *
 * It is authored by the system, not by whoever asked — nobody pressed a button
 * to send it — and it points at the answer queue rather than a single task,
 * since it usually stands for several.
 */
class WaitingReminder extends Notification
{
    use Queueable;

    /**
     * @param  Collection<int, Task>  $tasks  the overdue requests, oldest first
     */
    public function __construct(public Project $project, public Collection $tasks) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * The payload mirrors {@see ItemActivity} so the inbox and the header panel
     * render it without a special case: the oldest request supplies the subject
     * and the reference (which is also what the inbox's project filter matches
     * on), and `count` says how many more it stands for.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $oldest = $this->tasks->first();

        return [
            'action' => 'waiting_reminder',
            'subject_type' => class_basename(Task::class),
            'subject_id' => $oldest->getKey(),
            'reference' => $oldest->reference,
            'title' => $oldest->title,
            'actor' => null,
            'count' => $this->tasks->count(),
            'url' => route('waiting.index'),
        ];
    }
}
