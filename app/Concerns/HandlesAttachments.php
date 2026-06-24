<?php

namespace App\Concerns;

use App\Models\Attachment;
use App\Models\Note;
use App\Models\Project;
use App\Models\Task;
use App\Support\Thumbnail;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Adds attachment uploading, listing, and removal to a page component that
 * renders a single Project, Task or Note.
 *
 * @property string $description
 */
trait HandlesAttachments
{
    use WithFileUploads;

    /** @var array<int, TemporaryUploadedFile> */
    public array $newFiles = [];

    public ?TemporaryUploadedFile $inlineImage = null;

    /**
     * The model that uploaded files should be attached to.
     */
    abstract protected function attachable(): Project|Task|Note;

    /**
     * Persist freshly dropped or selected files as soon as they finish uploading.
     */
    public function updatedNewFiles(): void
    {
        if ($this->newFiles === []) {
            return;
        }

        $attachable = $this->attachable();
        $this->authorize('create', [Attachment::class, $attachable]);

        $maxSize = (int) config('attachments.max_size');

        $this->validate([
            'newFiles' => ['array'],
            'newFiles.*' => ['file', "max:{$maxSize}"],
        ]);

        foreach ($this->newFiles as $file) {
            $this->storeAttachment($file, $attachable);
        }

        // Notes don't keep an activity log; only Project/Task tile uploads do.
        if (! $attachable instanceof Note) {
            $attachable->recordActivity('attachment_added', 'attachments');
        }

        $this->reset('newFiles');
        unset($this->attachments);

        Flux::toast(text: __('Attachment uploaded.'), variant: 'success');
    }

    /**
     * Persist a pasted or dropped image as an inline attachment and return its
     * (relative, host-portable) URLs so the editor can insert a thumbnail that
     * links to the full-size original.
     *
     * @return array{src: string, href: string}|null
     */
    public function addInlineImage(): ?array
    {
        if ($this->inlineImage === null) {
            return null;
        }

        $attachable = $this->attachable();
        $this->authorize('create', [Attachment::class, $attachable]);

        $maxSize = (int) config('attachments.max_size');

        // A rejected file (e.g. a HEIC/AVIF phone photo, which the `image` rule
        // doesn't accept) must not leave the editor stuck on its "Uploading…"
        // spinner with no feedback: surface a toast and bail instead of throwing.
        try {
            $this->validate([
                'inlineImage' => ['image', "max:{$maxSize}"],
            ]);
        } catch (ValidationException) {
            $this->reset('inlineImage');

            Flux::toast(
                variant: 'danger',
                text: __('That image could not be added. Please use a JPG, PNG, GIF or WebP image.'),
            );

            return null;
        }

        $attachment = $this->storeAttachment($this->inlineImage, $attachable, isInline: true);
        $this->reset('inlineImage');

        $attachment->setRelation('attachable', $attachable);

        $full = $attachment->viewUrl(absolute: false);

        return [
            'src' => $attachment->hasThumbnail() ? $attachment->thumbnailUrl(absolute: false) : $full,
            'href' => $full,
        ];
    }

    public function deleteAttachment(int $attachmentId): void
    {
        $attachable = $this->attachable();
        $attachment = $attachable->attachments()->findOrFail($attachmentId);
        $this->authorize('delete', $attachment);

        $attachment->delete();

        if (! $attachable instanceof Note) {
            $attachable->recordActivity('attachment_removed', 'attachments', $attachment->name);
        }

        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachment removed.'));
    }

    /**
     * The downloadable file attachments, excluding images embedded in the
     * description, which are shown inline instead of as tiles.
     *
     * @return Collection<int, Attachment>
     */
    #[Computed]
    public function attachments(): Collection
    {
        $attachable = $this->attachable();

        // Hydrate the attachable relation from the already-loaded page model so
        // building project-scoped URLs does not trigger extra queries.
        return $attachable->attachments()
            ->where('is_inline', false)
            ->get()
            ->each(static fn (Attachment $attachment) => $attachment->setRelation('attachable', $attachable));
    }

    /**
     * Move an uploaded file onto the configured disk and create its attachment
     * record, generating a preview thumbnail when possible.
     */
    private function storeAttachment(TemporaryUploadedFile $file, Project|Task|Note $attachable, bool $isInline = false): Attachment
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

        return $attachable->attachments()->create([
            'disk' => $disk,
            'path' => $path,
            'thumbnail_path' => $this->storeThumbnail((string) $contents, $mimeType, $disk, $directory),
            'name' => $name,
            'mime_type' => $mimeType,
            'size' => $size,
            'is_inline' => $isInline,
            'uploaded_by' => auth()->id(),
        ]);
    }

    /**
     * Generate and store a preview thumbnail, returning its path, or null when
     * no thumbnail can be created for the file.
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
