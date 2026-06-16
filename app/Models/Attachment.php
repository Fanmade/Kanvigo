<?php

namespace App\Models;

use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $attachable_type
 * @property int $attachable_id
 * @property int|null $uploaded_by
 * @property string $disk
 * @property string $path
 * @property string|null $thumbnail_path
 * @property string $name
 * @property string|null $mime_type
 * @property int $size
 * @property bool $is_inline
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $uploader
 * @property-read Model $attachable
 */
#[Fillable(['disk', 'path', 'thumbnail_path', 'name', 'mime_type', 'size', 'is_inline', 'uploaded_by'])]
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(static function (Attachment $attachment): void {
            $disk = Storage::disk($attachment->disk);
            $disk->delete($attachment->path);

            if ($attachment->thumbnail_path !== null) {
                $disk->delete($attachment->thumbnail_path);
            }
        });
    }

    /**
     * The Project, Story, or Task this file is attached to.
     *
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The user who uploaded this attachment.
     *
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * The project this attachment ultimately belongs to, resolved through its
     * Project, Story, or Task owner.
     */
    public function ownerProject(): ?Project
    {
        return match (true) {
            $this->attachable instanceof Project => $this->attachable,
            $this->attachable instanceof Story => $this->attachable->project,
            $this->attachable instanceof Task => $this->attachable->project(),
            default => null,
        };
    }

    /**
     * Whether a preview thumbnail was generated for this attachment.
     */
    public function hasThumbnail(): bool
    {
        return $this->thumbnail_path !== null;
    }

    public function downloadUrl(bool $absolute = true): string
    {
        return $this->scopedUrl('attachments.download', $absolute);
    }

    public function thumbnailUrl(bool $absolute = true): string
    {
        return $this->scopedUrl('attachments.thumbnail', $absolute);
    }

    public function viewUrl(bool $absolute = true): string
    {
        return $this->scopedUrl('attachments.view', $absolute);
    }

    /**
     * Build a project-scoped attachment route URL.
     */
    private function scopedUrl(string $name, bool $absolute): string
    {
        return route($name, [
            'short_name' => $this->ownerProject()?->short_name,
            'attachment' => $this,
        ], $absolute);
    }

    /**
     * The Heroicon name that best represents this attachment's type.
     */
    public function iconName(): string
    {
        return match (true) {
            str_starts_with((string) $this->mime_type, 'image/') => 'photo',
            $this->mime_type === 'application/pdf' => 'document-text',
            str_starts_with((string) $this->mime_type, 'video/') => 'film',
            str_starts_with((string) $this->mime_type, 'audio/') => 'musical-note',
            default => 'document',
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'is_inline' => 'boolean',
        ];
    }
}
