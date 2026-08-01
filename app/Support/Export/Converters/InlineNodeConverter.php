<?php

namespace App\Support\Export\Converters;

use App\Support\Export\ExportImages;
use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\ElementInterface;

/**
 * Converts the inline nodes only Kanvigo knows about — cross-references,
 * mentions and variable usages — plus the ordinary anchors and images around
 * them, whose relative URLs have to become absolute to survive leaving the app.
 *
 * Everything else is left to the library's own converters; see
 * docs/adr/0003-html-to-markdown-library.md.
 */
final class InlineNodeConverter implements ConverterInterface
{
    /**
     * @param  string  $baseUrl  the instance's absolute root, used to resolve the
     *                           relative hrefs and image sources stored in content
     * @param  ExportImages  $images  decides what each inline image becomes
     * @param  array<string, string>  $localLinks  "task:12" => the path to use
     *                                             instead of the absolute URL,
     *                                             for items that travel in the
     *                                             same bundle as this file
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly ExportImages $images,
        private readonly array $localLinks = [],
    ) {}

    public function convert(ElementInterface $element): string
    {
        return match ($element->getTagName()) {
            'a' => $this->convertAnchor($element),
            'img' => $this->convertImage($element),
            default => $this->convertSpan($element),
        };
    }

    /**
     * @return list<string>
     */
    public function getSupportedTags(): array
    {
        return ['a', 'img', 'span'];
    }

    /**
     * A cross-reference links to the item's page on this instance; a mention is
     * plain `@name`, because a Markdown file taken elsewhere cannot notify
     * anyone and a link to a profile page nobody can open is noise. Ordinary
     * links keep their text and get an absolute URL.
     */
    private function convertAnchor(ElementInterface $element): string
    {
        $text = $element->getValue();

        if ($element->getAttribute('data-type') === 'mention') {
            return '@'.($element->getAttribute('data-label') ?: ltrim($text, '@'));
        }

        $href = $element->getAttribute('href');

        if ($href === '') {
            return $text;
        }

        if ($element->getAttribute('data-type') === 'reference') {
            $text = $element->getAttribute('data-label') ?: $text;

            $local = $this->localLinks[$element->getAttribute('data-item-type').':'.$element->getAttribute('data-id')] ?? null;

            // A reference to something that travels in the same archive points at
            // the file, so the bundle reads as a corpus rather than as a pile of
            // links back to an instance the reader may not be able to reach.
            if ($local !== null) {
                return '['.$text.']('.$local.')';
            }
        }

        return '['.$text.']('.$this->absolute($href).')';
    }

    /**
     * What an inline image becomes is the export's choice — an embedded URL, a
     * link, or the picture itself as a data URI — so the decision lives in
     * {@see ExportImages} and this converter only supplies what it needs.
     */
    private function convertImage(ElementInterface $element): string
    {
        $src = $element->getAttribute('src');

        return $this->images->markdownFor(
            $src,
            $element->getAttribute('alt') ?: $element->getAttribute('title'),
            $this->absolute($src),
        );
    }

    /**
     * Spans carry mentions (before they are linked) and variable usages, which
     * the substitutor has already resolved to the value — or, when the variable
     * has no value yet, to its own name. Both cases are the span's own text, so
     * an unrecognised span simply contributes its content.
     */
    private function convertSpan(ElementInterface $element): string
    {
        $text = $element->getValue();

        if ($element->getAttribute('data-type') === 'mention') {
            return '@'.($element->getAttribute('data-label') ?: ltrim($text, '@'));
        }

        return $text;
    }

    /**
     * Resolve a stored URL against this instance's root. Content written through
     * the API may already carry an absolute URL, which is left alone.
     */
    private function absolute(string $url): string
    {
        if ($url === '' || ! str_starts_with($url, '/')) {
            return $url;
        }

        return rtrim($this->baseUrl, '/').$url;
    }
}
