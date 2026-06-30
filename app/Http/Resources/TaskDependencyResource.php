<?php

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The relationship view of a task returned by the dependency endpoints: the task
 * reference plus its relationships grouped by keyword (blocked_by, blocks,
 * relates, …) and the is_blocked flag. Keeps the dependency responses inside the
 * v1 Resource envelope instead of hand-built arrays.
 *
 * @mixin Task
 */
class TaskDependencyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            ...$this->relationshipPayload(),
        ];
    }
}
