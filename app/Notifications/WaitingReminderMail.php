<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Concerns\OptsIntoMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * The e-mail counterpart of {@see WaitingReminder}: one mail for a project's
 * whole backlog of unanswered requests, not one per task.
 *
 * Queued for the same reason its in-app twin is not: the reminder command walks
 * every project and every awaited person, and a synchronous send would make one
 * slow SMTP handshake hold up the whole nightly run.
 */
class WaitingReminderMail extends Notification implements ShouldQueue
{
    use OptsIntoMail;
    use Queueable;

    /**
     * @param  Collection<int, Task>  $tasks  the overdue requests, oldest first
     */
    public function __construct(public Project $project, public Collection $tasks) {}

    /**
     * A waiting request is always a task, so it follows the task switch.
     */
    protected function mailLevelKey(): ?string
    {
        return User::EMAIL_TASKS_PREFERENCE_KEY;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = $this->tasks->count();

        return (new MailMessage)
            ->subject(trans_choice(
                '{1} A request is waiting for your answer|[2,*] :count requests are waiting for your answer',
                $count,
                ['count' => $count],
            ))
            ->markdown('emails.waiting-reminder', [
                'project' => $this->project,
                'requests' => $this->tasks,
                'count' => $count,
                'url' => route('waiting.index'),
            ]);
    }
}
