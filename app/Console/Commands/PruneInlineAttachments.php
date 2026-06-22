<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Support\InlineAttachments;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('attachments:prune-inline {--hours=24 : Minimum age in hours before an unreferenced inline image is removed}')]
#[Description('Delete inline image attachments no longer referenced by their owner description or any of its comments.')]
class PruneInlineAttachments extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $cutoff = now()->subHours((int) $this->option('hours'));
        $deleted = 0;

        Attachment::query()
            ->where('is_inline', true)
            ->where('created_at', '<', $cutoff)
            ->chunkById(100, function (Collection $attachments) use (&$deleted): void {
                /** @var Collection<int, Attachment> $attachments */
                foreach ($attachments as $attachment) {
                    if ($this->isReferenced($attachment)) {
                        continue;
                    }

                    $attachment->delete();
                    $deleted++;
                }
            });

        $this->info("Pruned {$deleted} orphaned inline attachment(s).");

        return self::SUCCESS;
    }

    /**
     * Whether the attachment is still linked from its owner's description or any
     * of the owner's comment bodies.
     *
     * Inline images are embedded pointing at the view and thumbnail routes, which
     * both contain "attachments/{id}/".
     */
    private function isReferenced(Attachment $attachment): bool
    {
        // Read through the relation query so a deleted owner surfaces as null
        // (a morphed-away attachment is, by definition, unreferenced).
        $owner = $attachment->attachable()->first();

        if (! $owner instanceof Project && ! $owner instanceof Task) {
            return false;
        }

        return in_array($attachment->id, InlineAttachments::referencedIdsForOwner($owner), true);
    }
}
