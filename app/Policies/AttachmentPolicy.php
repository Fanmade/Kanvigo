<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\Project;
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
     * Uploading needs manage-attachments in the parent's project; attachments on
     * a (projectless) note fall back to note ownership.
     */
    public function create(User $user, Model $attachable): bool
    {
        $project = Project::ownerOf($attachable);

        return $project !== null
            ? $user->hasScopedPermission('manage-attachments', $project)
            : $user->can('update', $attachable);
    }

    /**
     * Deleting needs delete-attachment in the parent's project; note attachments
     * fall back to note ownership.
     */
    public function delete(User $user, Attachment $attachment): bool
    {
        $project = $attachment->ownerProject();

        return $project !== null
            ? $user->hasScopedPermission('delete-attachment', $project)
            : $user->can('update', $attachment->attachable);
    }
}
