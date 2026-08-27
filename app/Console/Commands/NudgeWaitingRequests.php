<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\WaitingReminder;
use App\Notifications\WaitingReminderMail;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

#[Signature('tasks:nudge-waiting')]
#[Description('Remind people about "waiting on" requests left unanswered past their project\'s threshold.')]
class NudgeWaitingRequests extends Command
{
    /**
     * Execute the console command.
     *
     * A request is nudged once it has been open longer than the threshold, and
     * then again only after another full interval — `waiting_nudged_at` is what
     * keeps a daily schedule from turning into daily mail. Requests are bundled
     * per awaited person per project, so one notification covers the backlog.
     */
    public function handle(): int
    {
        $reminded = 0;

        Project::query()->each(function (Project $project) use (&$reminded): void {
            $days = $project->waitingNudgeThresholdDays();

            if ($days === null) {
                return;
            }

            $cutoff = Carbon::now()->subDays($days);
            $overdue = $this->overdueRequests($project, $cutoff);

            $overdue->groupBy('waiting_on_user_id')->each(
                function (Collection $tasks) use ($project, &$reminded): void {
                    $awaited = $tasks->first()->waitingOn;

                    if (! $awaited instanceof User) {
                        return;
                    }

                    $awaited->notify(new WaitingReminder($project, $tasks));
                    $awaited->notify(new WaitingReminderMail($project, $tasks));

                    $tasks->each(static fn (Task $task) => $task->forceFill([
                        'waiting_nudged_at' => Carbon::now(),
                    ])->saveQuietly());

                    $reminded++;
                });
        });

        $this->info("Sent {$reminded} waiting reminder(s).");

        return self::SUCCESS;
    }

    /**
     * The project's requests that are past the threshold and have not been
     * reminded about within the current interval, oldest wait first.
     *
     * @return Collection<int, Task>
     */
    private function overdueRequests(Project $project, Carbon $cutoff): Collection
    {
        return $project->tasks()
            ->whereNotNull('waiting_on_user_id')
            ->whereNotNull('waiting_since')
            ->where('waiting_since', '<=', $cutoff)
            ->whereNull('canceled_at')
            ->whereNull('archived_at')
            ->where(static fn ($query) => $query
                ->whereNull('waiting_nudged_at')
                ->orWhere('waiting_nudged_at', '<=', $cutoff))
            ->with('waitingOn')
            ->orderBy('waiting_since')
            ->get();
    }
}
