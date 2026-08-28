<?php

namespace App\Concerns;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasAttachments
{
    /**
     * The files attached to this model, newest first.
     *
     * Timestamps have second precision, so files uploaded in one batch share a
     * created_at and the sort ties. The id breaks the tie: without it the
     * database is free to return a tied batch in any order, which made the
     * gallery order (and its lightbox positions) vary between page loads.
     *
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')
            ->latest()
            ->latest('id');
    }
}
