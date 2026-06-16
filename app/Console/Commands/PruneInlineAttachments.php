<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('attachments:prune-inline {--hours=24 : Minimum age in hours before an unreferenced inline image is removed}')]
#[Description('Delete inline image attachments no longer referenced in their parent description.')]
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
     * Whether the attachment is still linked from its parent's description.
     *
     * Inline images are embedded as markdown pointing at the download and
     * thumbnail routes, which both contain "attachments/{id}/".
     */
    private function isReferenced(Attachment $attachment): bool
    {
        // Read through the relation query so a deleted parent surfaces as null
        // (a morphed-away attachment is, by definition, unreferenced).
        $attachable = $attachment->attachable()->first();

        if ($attachable === null) {
            return false;
        }

        $description = (string) ($attachable->description ?? '');

        return str_contains($description, "attachments/{$attachment->id}/");
    }
}
