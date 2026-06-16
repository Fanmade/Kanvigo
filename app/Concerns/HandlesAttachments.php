<?php

namespace App\Concerns;

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Support\Thumbnail;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Adds attachment uploading, listing, and removal to a page component that
 * renders a single Project, Story, or Task.
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
    abstract protected function attachable(): Project|Story|Task;

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

        $attachable->recordActivity('attachment_added', 'attachments');

        $this->reset('newFiles');
        unset($this->attachments);

        Flux::toast(text: __('Attachment uploaded.'), variant: 'success');
    }

    /**
     * Persist a pasted or dropped image as an inline attachment and splice a
     * markdown reference to it into the description at the given cursor offset.
     */
    public function addInlineImage(int $cursor = 0): void
    {
        if ($this->inlineImage === null) {
            return;
        }

        $attachable = $this->attachable();
        $this->authorize('create', [Attachment::class, $attachable]);

        $maxSize = (int) config('attachments.max_size');

        $this->validate([
            'inlineImage' => ['image', "max:{$maxSize}"],
        ]);

        $attachment = $this->storeAttachment($this->inlineImage, $attachable, isInline: true);
        $this->reset('inlineImage');

        // Relative URLs so the markdown stays portable across hosts.
        $attachment->setRelation('attachable', $attachable);
        $full = $attachment->viewUrl(absolute: false);
        $preview = $attachment->hasThumbnail()
            ? $attachment->thumbnailUrl(absolute: false)
            : $full;

        // A thumbnail image that links through to the full-size image (shown
        // inline in a new tab, not downloaded).
        $markdown = sprintf('[![%s](%s)](%s)', $attachment->name, $preview, $full);

        $cursor = max(0, min($cursor, mb_strlen($this->description)));
        $this->description = mb_substr($this->description, 0, $cursor).$markdown.mb_substr($this->description, $cursor);
    }

    public function deleteAttachment(int $attachmentId): void
    {
        $attachment = $this->attachable()->attachments()->findOrFail($attachmentId);
        $this->authorize('delete', $attachment);

        $attachment->delete();

        $this->attachable()->recordActivity('attachment_removed', 'attachments', $attachment->name);

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
    private function storeAttachment(TemporaryUploadedFile $file, Project|Story|Task $attachable, bool $isInline = false): Attachment
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
