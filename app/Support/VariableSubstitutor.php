<?php

namespace App\Support;

use App\Models\Variable;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use Illuminate\Support\Facades\DB;

/**
 * Resolves `[name]` variable usages in rendered rich-text to the value the
 * project's variable currently holds.
 *
 * Substitution happens at read time and never touches stored content: a document
 * keeps saying `[main_protagonist]` forever, so changing what it stands for is
 * one row, not a rewrite (see docs/adr/0001-project-variables.md). It runs after
 * {@see RichTextSanitizer}, on already-safe HTML, so the markup it inserts is
 * not at the mercy of the allow-list — and the value, which is plain author
 * text, is escaped here.
 *
 * What counts as a usage is {@see VariableSyntax}, shared with the usage index
 * so the two can never disagree. A name only resolves when the project actually
 * defines it: unknown bracketed text is left exactly as written, so footnote
 * markers and ordinary prose are never mangled. A defined but valueless variable renders its own name in the
 * "unset" style — a visible hole, which is the placeholder workflow the feature
 * exists for. Values are never themselves substituted, so a value containing
 * `[other]` renders literally and no cycle is possible on the render path.
 */
class VariableSubstitutor
{
    /**
     * The loaded variable maps, keyed by project short name — one query per
     * project per request, however many descriptions and comments a page shows.
     *
     * @var array<string, array<string, string|null>>
     */
    private array $maps = [];

    /**
     * Replace every usage of a variable this project defines with its current
     * value. Passing no project short name (content outside a project namespace,
     * such as a personal note) returns the HTML untouched.
     */
    public function substitute(string $html, ?string $projectShortName): string
    {
        // Cheap bail-out: no bracket, nothing to resolve — and no query.
        if ($projectShortName === null || $projectShortName === '' || ! str_contains($html, '[')) {
            return $html;
        }

        $variables = $this->mapFor($projectShortName);

        if ($variables === []) {
            return $html;
        }

        $document = HTMLDocument::createFromString('<div>'.$html.'</div>', LIBXML_NOERROR);
        $wrapper = $document->querySelector('div');

        if (! $wrapper instanceof Element) {
            return $html;
        }

        $this->substituteInNode($document, $wrapper, $variables);

        return $wrapper->innerHTML;
    }

    /**
     * The project's variables as name => value (null when unset), memoized for
     * the request.
     *
     * @return array<string, string|null>
     */
    private function mapFor(string $projectShortName): array
    {
        return $this->maps[$projectShortName] ??= DB::table('variables')
            ->join('projects', 'projects.id', '=', 'variables.project_id')
            ->where('projects.short_name', $projectShortName)
            ->pluck('variables.value', 'variables.name')
            ->all();
    }

    /**
     * Walk the node's children, rewriting usages in text and recursing into
     * elements. Verbatim elements are skipped whole.
     *
     * @param  array<string, string|null>  $variables
     */
    private function substituteInNode(HTMLDocument $document, Node $node, array $variables): void
    {
        // Snapshot the children: replacing a text node mutates the live list.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof Text) {
                $this->substituteInText($document, $child, $variables);

                continue;
            }

            if ($child instanceof Element && ! VariableSyntax::isVerbatim($child)) {
                $this->substituteInNode($document, $child, $variables);
            }
        }
    }

    /**
     * Replace the usages in a single text node with the rendered variable
     * elements, leaving the surrounding text as it was.
     *
     * @param  array<string, string|null>  $variables
     */
    private function substituteInText(HTMLDocument $document, Text $text, array $variables): void
    {
        $content = $text->textContent;

        if (! str_contains($content, '[')) {
            return;
        }

        $parts = preg_split(VariableSyntax::PATTERN, $content, flags: PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false || count($parts) === 1) {
            return;
        }

        $fragment = $document->createDocumentFragment();
        $replaced = false;

        // preg_split with DELIM_CAPTURE alternates literal text and captured
        // names, so odd offsets are the names.
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0 || ! array_key_exists($part, $variables)) {
                // Literal text, or a name this project does not define: keep the
                // usage exactly as the author wrote it.
                $fragment->appendChild($document->createTextNode($index % 2 === 0 ? $part : '['.$part.']'));

                continue;
            }

            $fragment->appendChild($this->render($document, $part, $variables[$part]));
            $replaced = true;
        }

        if ($replaced) {
            $text->parentNode?->replaceChild($fragment, $text);
        }
    }

    /**
     * The element a single usage renders as: the value in normal prose weight
     * with a subtle marker, or — when the variable has no value yet — its own
     * name in the "undecided" style. The name rides along as a data attribute;
     * the hovercard reads the value off the element's own text, so an author's
     * value is never repeated into an attribute.
     */
    private function render(HTMLDocument $document, string $name, ?string $value): Element
    {
        $element = $document->createElement('span');
        $element->setAttribute('class', $value === null ? 'variable variable-unset' : 'variable');
        $element->setAttribute('data-variable', $name);

        // The hovercard is rendered client-side, so the "no value yet" wording is
        // translated here rather than in JavaScript. Its presence is also how the
        // card tells an unset variable from one whose value happens to be its name.
        if ($value === null) {
            $element->setAttribute('data-unset-label', __('No value yet'));
        }

        $element->textContent = $value ?? $name;

        return $element;
    }
}
