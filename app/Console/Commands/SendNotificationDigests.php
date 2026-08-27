<?php

namespace App\Console\Commands;

use App\Models\Notification as NotificationRecord;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ActivityDigest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

#[Signature('notifications:send-digests')]
#[Description('E-mail a catch-up digest to users who asked for one and whose interval has elapsed.')]
class SendNotificationDigests extends Command
{
    /**
     * Execute the console command.
     *
     * Runs daily; a weekly subscriber is simply skipped until seven days have
     * passed. The digest is assembled from the recipient's own notification
     * records, so it shows exactly what their inbox would — nothing more.
     */
    public function handle(): int
    {
        $sent = 0;

        User::query()
            ->whereNotNull('email_verified_at')
            ->each(function (User $user) use (&$sent): void {
                $mode = $user->deliveryMode();
                $interval = $mode->intervalDays();

                if ($interval === null || ! $user->preference(User::EMAIL_PREFERENCE_KEY, false)) {
                    return;
                }

                if (! $this->isDue($user, $interval)) {
                    return;
                }

                $notifications = $this->digestible($user);

                // Nothing unread means nothing to say. The cursor still moves, so
                // a quiet week does not make the next digest reach back further
                // than the interval it promises.
                if ($notifications->isNotEmpty()) {
                    $user->notify(new ActivityDigest($notifications, $mode->label()));
                    $sent++;
                }

                $user->forceFill(['digest_sent_at' => Carbon::now()])->saveQuietly();
            });

        $this->info("Sent {$sent} digest(s).");

        return self::SUCCESS;
    }

    /**
     * Whether enough time has passed since this user's last digest. Someone who
     * has never had one is due immediately.
     */
    private function isDue(User $user, int $intervalDays): bool
    {
        $last = $user->digest_sent_at;

        return $last === null || $last->lte(Carbon::now()->subDays($intervalDays));
    }

    /**
     * The unread notifications the digest covers: everything since the last one,
     * narrowed by the per-level switches so a digest respects the same choices
     * immediate mail does.
     *
     * @return Collection<int, NotificationRecord>
     */
    private function digestible(User $user): Collection
    {
        $levels = array_values(array_filter([
            $user->preference(User::EMAIL_TASKS_PREFERENCE_KEY, true) ? class_basename(Task::class) : null,
            $user->preference(User::EMAIL_PROJECTS_PREFERENCE_KEY, true) ? class_basename(Project::class) : null,
        ]));

        if ($levels === []) {
            return new Collection;
        }

        return $user->unreadNotifications()
            ->when(
                $user->digest_sent_at !== null,
                static fn ($query) => $query->where('created_at', '>', $user->digest_sent_at),
            )
            ->whereIn('data->subject_type', $levels)
            ->latest()
            ->get();
    }
}
