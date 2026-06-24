<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tasks:auto-archive')]
#[Description('Archive tasks that have stayed in Done longer than each project\'s auto-archive threshold.')]
class ArchiveCompletedTasks extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $archived = 0;

        Project::query()->each(function (Project $project) use (&$archived): void {
            $days = $project->autoArchiveThresholdDays();

            if ($days === null) {
                return;
            }

            $cutoff = now()->subDays($days);

            $project->tasks()
                ->where('status', Status::Done)
                ->whereNull('archived_at')
                ->whereNotNull('completed_at')
                ->where('completed_at', '<=', $cutoff)
                ->get()
                ->each(function (Task $task) use (&$archived): void {
                    $task->archive();
                    $archived++;
                });
        });

        $this->info("Auto-archived {$archived} task(s).");

        return self::SUCCESS;
    }
}
