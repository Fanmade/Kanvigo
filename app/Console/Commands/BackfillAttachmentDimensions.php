<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Support\Images\ImageTransformer;
use App\Support\Images\RasterImageTypes;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Fills in width/height for attachments stored before those columns existed.
 * Safe to re-run: rows that already carry dimensions, and rows whose bytes no
 * driver can decode, are skipped. Only the raster types we hand to a decoder are
 * measured at all ({@see RasterImageTypes}).
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
            ->whereIn('mime_type', RasterImageTypes::ALLOWED)
            ->chunkById(100, static function (Collection $attachments) use ($transformer, &$measured): void {
                /** @var Collection<int, Attachment> $attachments */
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
