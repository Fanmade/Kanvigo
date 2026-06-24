<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitizes stored rich-text (HTML) descriptions before they are rendered.
 *
 * Descriptions are authored with the Flux editor (and migrated from the old
 * Markdown), so the content is HTML. Because the MCP/API can also write
 * description content directly, every value is run through an allow-list
 * sanitizer on output to prevent stored XSS. The allow-list covers the tags the
 * editor produces plus the inline-image markup (a thumbnail linking to the
 * full-size attachment via relative routes).
 */
class RichTextSanitizer
{
    private readonly HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            // `a` also carries reference links (#KAN-42) and `span` carries user
            // mentions (@name); both are atomic inline nodes tagged with
            // data-type/data-id so they survive sanitisation and stay parseable.
            ->allowElement('a', ['href', 'title', 'target', 'rel', 'class', 'data-type', 'data-id', 'data-label'])
            ->allowElement('span', ['class', 'data-type', 'data-id', 'data-label'])
            ->allowElement('img', ['src', 'alt', 'title'])
            ->allowElement('del')
            ->allowElement('s')
            ->allowElement('u')
            ->allowElement('sub')
            ->allowElement('sup')
            ->allowElement('mark')
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->forceAttribute('a', 'rel', 'noopener noreferrer');

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(?string $html): string
    {
        return $this->sanitizer->sanitize($html ?? '');
    }
}
