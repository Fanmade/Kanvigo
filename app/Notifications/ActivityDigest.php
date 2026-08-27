<?php

namespace App\Notifications;

use App\Livewire\Notifications\Concerns\DescribesNotifications;
use App\Models\Notification as NotificationRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * The periodic catch-up mail for someone who asked for a digest rather than a
 * mail per event.
 *
 * It carries the notification records themselves — the same rows the in-app
 * inbox shows — so the digest can never reveal anything the recipient could not
 * already see: subscription, actor exclusion and per-item access were all
 * settled when those rows were written.
 *
 * Unlike the other mail notifications this one is not gated by
 * {@see Concerns\OptsIntoMail}: the command has already decided who is due, and
 * a digest that reached the send stage should not then filter itself away.
 */
class ActivityDigest extends Notification implements ShouldQueue
{
    use DescribesNotifications;
    use Queueable;

    /**
     * @param  Collection<int, NotificationRecord>  $notifications  what is still unread, newest first
     */
    public function __construct(public Collection $notifications, public string $period) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = $this->notifications->count();

        return (new MailMessage)
            ->subject(trans_choice(
                '{1} One update you have not read|[2,*] :count updates you have not read',
                $count,
                ['count' => $count],
            ))
            ->markdown('emails.digest', [
                'lines' => $this->lines(),
                'count' => $count,
                'period' => $this->period,
                'url' => route('notifications.index'),
            ]);
    }

    /**
     * The digest body, one readable line per notification, in the same wording
     * the inbox uses so the mail and the app never describe an event
     * differently.
     *
     * @return array<int, array{reference: string, title: string|null, text: string, url: string|null}>
     */
    private function lines(): array
    {
        return $this->notifications->map(function (NotificationRecord $notification): array {
            $data = $notification->data;

            return [
                'reference' => (string) ($data['reference'] ?? ''),
                'title' => $data['title'] ?? null,
                'text' => trim(($data['actor'] ?? __('System')).' '.$this->actionLabel($data['action'] ?? '')),
                'url' => is_string($data['url'] ?? null) ? $data['url'] : null,
            ];
        })->values()->all();
    }
}
