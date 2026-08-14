<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Support\Images\ImageTransformer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Fills in width/height for attachments stored before those columns existed.
 * Safe to re-run: rows that already carry dimensions, and rows whose bytes no
 * driver can decode, are skipped.
 */
class BackfillAttachmentDimensions extends Command
{
    protected $signature = 'attachments:backfill-dimensions';

    protected $description = 'Measure and store the pixel dimensions of existing image attachments';

    public function handle(ImageTransformer $transformer): int
    {
        $measured = 0;

        Attachment::query()
            ->whereNull('width')
            ->where('mime_type', 'like', 'image/%')
            ->chunkById(100, static function ($attachments) use ($transformer, &$measured): void {
                foreach ($attachments as $attachment) {
                    $disk = Storage::disk($attachment->disk);

                    if (! $disk->exists($attachment->path)) {
                        continue;
                    }

                    $dimensions = $transformer->dimensions((string) $disk->get($attachment->path));

                    if ($dimensions === null) {
                        continue;
                    }

                    $attachment->forceFill(['width' => $dimensions[0], 'height' => $dimensions[1]])->save();
                    $measured++;
                }
            });

        $this->info("Measured {$measured} attachment(s).");

        return self::SUCCESS;
    }
}
