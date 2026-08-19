<?php

namespace App\Actions;

use App\Concerns\HandlesAttachments;
use App\Models\Attachment;
use App\Models\Doc;
use App\Models\Note;
use App\Models\Project;
use App\Models\Task;
use App\Support\Images\ImageTransformer;
use App\Support\Images\RasterImageTypes;
use App\Support\Thumbnail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Moves an uploaded file onto the configured disk and creates its attachment
 * record, generating a preview thumbnail when possible. Shared by the Livewire
 * upload path ({@see HandlesAttachments}) and the REST API.
 */
class StoreAttachment
{
    public function handle(UploadedFile $file, Project|Task|Note|Doc $attachable, bool $isInline = false, ?int $uploadedBy = null): Attachment
    {
        // Read metadata and contents before storing: when the temporary upload
        // and target disks match, store() moves the file, after which it can no
        // longer be inspected at its temporary location.
        $name = $file->getClientOriginalName();
        $mimeType = $file->getMimeType();
        $size = $file->getSize();
        $contents = $file->get();

        $disk = (string) config('attachments.disk');
        $directory = trim((string) config('attachments.directory'), '/');

        $path = $file->store($directory, $disk);

        // Only measure bytes whose MIME type is one of the raster formats we
        // decode. A decoder reads far more than that — PDF, EPS, PostScript, SVG —
        // and would store those dimensions as if they were pixels, which they are
        // not (PDF's are PostScript points); worse, those formats are handled by
        // delegates that shell out ({@see RasterImageTypes}). Matches the gate
        // attachments:backfill-dimensions already applies.
        $dimensions = RasterImageTypes::isDecodable($mimeType)
            ? app(ImageTransformer::class)->dimensions((string) $contents)
            : null;

        return $attachable->attachments()->create([
            'disk' => $disk,
            'path' => $path,
            'thumbnail_path' => $this->storeThumbnail((string) $contents, $mimeType, $disk, $directory),
            'name' => $name,
            'mime_type' => $mimeType,
            'size' => $size,
            'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
            'is_inline' => $isInline,
            'uploaded_by' => $uploadedBy ?? auth()->id(),
        ]);
    }

    /**
     * Generate and store a preview thumbnail, returning its path, or null when no
     * thumbnail can be created for the file.
     */
    private function storeThumbnail(string $contents, ?string $mimeType, string $disk, string $directory): ?string
    {
        $thumbnail = Thumbnail::generate($contents, $mimeType);

        if ($thumbnail === null) {
            return null;
        }

        $path = trim($directory.'/thumbnails/'.Str::uuid()->toString().'.png', '/');

        Storage::disk($disk)->put($path, $thumbnail);

        return $path;
    }
}
