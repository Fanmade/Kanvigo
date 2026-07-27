<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\SerializesReferences;
use App\Models\Attachment;
use App\Models\Doc;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The full doc representation returned by the show and write endpoints: the lean
 * {@see DocResource} fields plus the HTML body, the docs nested under it, its
 * cross-references and attachments. The list endpoint keeps using the lean
 * resource to stay cheap.
 *
 * @mixin Doc
 */
class DocDetailResource extends DocResource
{
    use SerializesReferences;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $shortName = $this->project->short_name;
        $user = Auth::user();

        return [
            ...parent::toArray($request),
            'body' => $this->body,
            'children' => $this->children
                ->filter(static fn (Doc $child): bool => (bool) $user?->can('view', $child))
                ->map(static fn (Doc $child): array => [
                    'reference' => $shortName.'-D'.$child->doc_number,
                    'title' => $child->title,
                    'is_public' => $child->is_public,
                ])->values()->all(),
            ...$this->referenceLists($this->resource),
            'attachments' => $this->attachments->map(static fn (Attachment $attachment): array => [
                'id' => $attachment->id,
                'name' => $attachment->name,
                'mime_type' => $attachment->mime_type,
                'is_inline' => $attachment->is_inline,
            ])->values()->all(),
        ];
    }
}
