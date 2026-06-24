<?php

namespace App\Models;

use App\Concerns\HasMentions;
use App\Concerns\PrunesInlineAttachments;
use App\Concerns\SanitizesRichText;
use App\Contracts\Mentionable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $commentable_type
 * @property int $commentable_id
 * @property int|null $parent_id
 * @property string $body
 * @property bool $is_deleted
 * @property string|null $delete_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
#[Fillable(['user_id', 'body', 'parent_id'])]
class Comment extends Model implements Mentionable
{
    use HasMentions;
    use PrunesInlineAttachments;
    use SanitizesRichText;

    /**
     * A comment's mentionable users are those of the project or task it is on, so
     * you can only @mention people with access to the surrounding item.
     *
     * @return list<int>
     */
    public function mentionableUserIds(): array
    {
        $commentable = $this->commentable;

        return $commentable instanceof Mentionable ? $commentable->mentionableUserIds() : [];
    }

    /**
     * A comment's mentions belong to the task or project it is on (where the
     * notification links and the subscription is recorded).
     */
    protected function mentionSubject(): Project|Task
    {
        return $this->inlineAttachmentOwner();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_deleted' => 'boolean',
        ];
    }

    public function inlineAttachmentOwner(): Project|Task
    {
        $commentable = $this->commentable;

        if ($commentable instanceof Project || $commentable instanceof Task) {
            return $commentable;
        }

        throw new \LogicException('A comment must belong to a project or task.');
    }

    public function inlineDocumentColumn(): string
    {
        return 'body';
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_id')->oldest();
    }
}
