<?php

namespace App\Notifications;

use App\Audit\Sinks\ActivityLogSink;
use App\Concerns\ResolvesSubjectUrl;
use App\Models\Activity;
use App\Models\User;
use App\Notifications\Concerns\OptsIntoMail;
use App\Support\ActivityDescriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The e-mail counterpart of {@see ItemActivity}.
 *
 * Mail travels in its own queued notification rather than as a second channel on
 * ItemActivity, because the two need opposite delivery: the in-app record is
 * written synchronously by {@see ActivityLogSink} inside the
 * request — the activity feed is documented as working without a queue worker —
 * while sending mail on that path would let a slow SMTP host stall a board drag.
 * Marking ItemActivity itself `ShouldQueue` would queue the database channel too
 * and break that promise.
 *
 * Recipients who have not opted in yield no channels ({@see OptsIntoMail}), so
 * no job is ever queued for them.
 */
class ItemActivityMail extends Notification implements ShouldQueue
{
    use OptsIntoMail;
    use Queueable;
    use ResolvesSubjectUrl;

    public function __construct(public Activity $activity) {}

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->activity->subject;
        $reference = $subject->reference ?? $subject->short_name ?? null;
        $description = ActivityDescriber::describe($this->activity);

        // A system-authored entry has no user; the same tombstone the rest of the
        // app shows stands in for one.
        $author = $this->activity->user;
        $actor = $author instanceof User ? $author->name : __('System');

        return (new MailMessage)
            ->subject($reference !== null
                ? __('[:reference] :actor :action', ['reference' => $reference, 'actor' => $actor, 'action' => $description])
                : __(':actor :action', ['actor' => $actor, 'action' => $description]))
            ->markdown('emails.activity', [
                'actor' => $actor,
                'description' => $description,
                'reference' => $reference,
                'title' => $subject->title ?? null,
                'url' => $this->activityUrl(),
            ]);
    }

    /**
     * Where the mail's button goes. A wait that has just been handed to somebody
     * belongs in their answer queue — that page is the one place the whole
     * backlog is actionable — and everything else on its own item's page.
     */
    private function activityUrl(): ?string
    {
        return $this->activity->action === 'waiting_on_changed'
            ? route('waiting.index')
            : $this->subjectUrl($this->activity->subject);
    }
}
