<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AttachmentPolicy
{
    /**
     * Access to an attachment cascades from access to the model it belongs to.
     */
    public function view(User $user, Attachment $attachment): bool
    {
        return $user->can('view', $attachment->attachable);
    }

    /**
     * Uploading is allowed for anyone who can update the parent model.
     */
    public function create(User $user, Model $attachable): bool
    {
        return $user->can('update', $attachable);
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        return $user->can('update', $attachment->attachable);
    }
}
