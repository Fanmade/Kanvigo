<?php

namespace App\Http\Resources;

use App\Models\Doc;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializes a reference doc into the same shape the MCP doc tools expose: the
 * flat per-project "PROJ-D<n>" reference, the parent's reference (or null for a
 * top-level doc) and the draft/published flag. The body and links live on
 * {@see DocDetailResource}, so lists stay cheap.
 *
 * @mixin Doc
 */
class DocResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'project' => $this->project->short_name,
            'parent' => $this->parent_id !== null && $this->parent !== null
                ? $this->project->short_name.'-D'.$this->parent->doc_number
                : null,
            'title' => $this->title,
            'is_public' => $this->is_public,
            'tags' => $this->tags->pluck('name')->values()->all(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
