<?php

namespace App\Support\Export\Converters;

use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\ElementInterface;

/**
 * Struck-through text, which the editor writes as `<del>`/`<s>`, as GitHub
 * Flavored Markdown's `~~text~~`. Without this the library leaves the raw tag
 * in place, which reads badly outside a browser.
 */
final class StrikethroughConverter implements ConverterInterface
{
    public function convert(ElementInterface $element): string
    {
        $text = $element->getValue();

        return $text === '' ? '' : '~~'.$text.'~~';
    }

    /**
     * @return list<string>
     */
    public function getSupportedTags(): array
    {
        return ['del', 's'];
    }
}
