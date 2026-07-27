<?php

namespace App\Queries;

use App\Models\Doc;
use Illuminate\Support\Str;

/**
 * The compact preview of a reference doc shown in the #reference hovercard: what
 * the doc is about — its title, whether it is still a draft and the opening of
 * its body — without opening it. The task counterpart is {@see TaskPreview}.
 */
class DocPreview
{
    /**
     * How much of the body the card shows before trailing off.
     */
    private const EXCERPT_LENGTH = 160;

    /**
     * @return array{
     *     type: string,
     *     reference: string,
     *     title: string,
     *     url: string,
     *     visibility: string,
     *     excerpt: string|null,
     *     nested: string|null,
     * }
     */
    public function handle(Doc $doc): array
    {
        $nested = $doc->children()->count();

        return [
            'type' => 'doc',
            'reference' => $doc->reference,
            'title' => $doc->title,
            'url' => route('doc.show', [
                'short_name' => $doc->project->short_name,
                'doc_number' => $doc->doc_number,
            ]),
            // Localized here, not in JS, which has no translator.
            'visibility' => $doc->is_public ? __('Published') : __('Draft'),
            'excerpt' => $this->excerpt($doc->body),
            'nested' => $nested > 0
                ? trans_choice(':count nested doc|:count nested docs', $nested, ['count' => $nested])
                : null,
        ];
    }

    /**
     * The opening of the body as plain text, or null when the doc is still empty.
     * The stored HTML is stripped rather than rendered: the card shows a summary,
     * never live markup.
     */
    private function excerpt(?string $body): ?string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $body)) ?? '');

        return $text === '' ? null : Str::limit($text, self::EXCERPT_LENGTH);
    }
}
