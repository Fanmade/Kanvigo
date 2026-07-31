<?php

namespace App\Support;

use App\Jobs\SyncVariableUsages;
use App\Models\Variable;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * The one definition of what a variable usage looks like in authored content,
 * shared by the render path ({@see VariableSubstitutor}) and the usage index
 * ({@see SyncVariableUsages}). They must agree: a name the index
 * records but rendering ignores — or the reverse — is a bug the panel would
 * report as fact.
 */
class VariableSyntax
{
    /**
     * A variable usage: the name pattern of {@see Variable::NAME_PATTERN} inside
     * square brackets. Strict on purpose — it is what keeps footnote markers like
     * [1] and [i], and other bracketed prose, from being mistaken for a usage.
     */
    public const string PATTERN = '/\[([a-z][a-z0-9_-]+)]/';

    /**
     * Elements whose text is quoted verbatim, where brackets are far more likely
     * to be array indexes or literal syntax than a variable usage.
     */
    public const array VERBATIM_ELEMENTS = ['code', 'pre'];

    /**
     * Every distinct name used in the given content, in first-seen order.
     * Names are not resolved here: a name no variable defines is still a usage,
     * which is how unknown names surface.
     *
     * @return list<string>
     */
    public static function namesIn(?string $html): array
    {
        // Cheap bail-out: no bracket, no usage — the common case for most content.
        if ($html === null || ! str_contains($html, '[')) {
            return [];
        }

        $document = HTMLDocument::createFromString('<div>'.$html.'</div>', LIBXML_NOERROR);
        $wrapper = $document->querySelector('div');

        if (! $wrapper instanceof Element) {
            return [];
        }

        $names = [];
        self::collect($wrapper, $names);

        return array_keys($names);
    }

    /**
     * Whether the element's content is quoted verbatim, so usages inside it are
     * literal text rather than variables.
     */
    public static function isVerbatim(Element $element): bool
    {
        return in_array(mb_strtolower($element->tagName), self::VERBATIM_ELEMENTS, true);
    }

    /**
     * Walk the node's children, collecting the names used in text and recursing
     * into elements. Verbatim elements are skipped whole, exactly as rendering
     * skips them.
     *
     * @param  array<string, true>  $names  keyed by name, so duplicates collapse
     */
    private static function collect(Node $node, array &$names): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof Text) {
                if (preg_match_all(self::PATTERN, $child->textContent, $matches)) {
                    foreach ($matches[1] as $name) {
                        $names[$name] = true;
                    }
                }

                continue;
            }

            if ($child instanceof Element && ! self::isVerbatim($child)) {
                self::collect($child, $names);
            }
        }
    }
}
