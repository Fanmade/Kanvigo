<?php

namespace App\Http\Resources;

use App\Models\Attachment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The full task representation returned by the show endpoint: the lean
 * {@see TaskResource} fields plus assignees, dependencies, subtasks, attachments,
 * the cancellation note and rolled-up progress. The list endpoints keep using the
 * lean resource to stay cheap.
 *
 * @mixin Task
 */
class TaskDetailResource extends TaskResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $progress = $this->progress();
        $shortName = $this->project->short_name;

        return [
            ...parent::toArray($request),
            'cancel_message' => $this->cancel_message,
            'progress' => ['done' => $progress->done, 'total' => $progress->total],
            'assignees' => $this->assignees->map(static fn (User $user): array => [
                'id' => $user->public_id,
                'name' => $user->name,
            ])->values()->all(),
            ...$this->relationshipReferences(),
            'children' => $this->children->map(static fn (Task $child): array => [
                'reference' => $shortName.'-'.$child->task_number,
                'title' => $child->title,
                'status' => $child->status->value,
            ])->values()->all(),
            'attachments' => $this->attachments->map(static fn (Attachment $attachment): array => [
                'id' => $attachment->id,
                'name' => $attachment->name,
                'mime_type' => $attachment->mime_type,
                'is_inline' => $attachment->is_inline,
            ])->values()->all(),
        ];
    }
}
