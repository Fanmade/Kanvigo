<?php

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializes a task into the same shape the MCP tools expose: enum values by
 * name for priority/cancel reason, the status string, the flat per-project
 * reference, and the parent's reference (or null for a top-level task).
 *
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'parent' => $this->parent_id !== null && $this->parent !== null
                ? $this->project->short_name.'-'.$this->parent->task_number
                : null,
            'title' => $this->title,
            'priority' => $this->priority->name,
            'status' => $this->status->value,
            'type' => $this->whenLoaded('taskType', fn () => $this->taskType?->name),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'cancel_reason' => $this->cancel_reason?->name,
            'tags' => $this->tags->pluck('name')->values()->all(),
            'is_blocked' => $this->isBlocked(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
