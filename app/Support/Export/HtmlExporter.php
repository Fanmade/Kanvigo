<?php

namespace App\Support\Export;

use App\Models\Doc;
use App\Models\Task;
use Illuminate\Support\Str;

/**
 * Renders an item as a standalone HTML page.
 *
 * It is the Markdown document, converted and wrapped: same front matter, same
 * subtree, same comments, same decisions about references, mentions, variables
 * and images. Going through Markdown rather than dressing up the stored HTML is
 * what keeps the two formats saying the same thing — and the stored fragment on
 * its own is not a page anyone can open.
 *
 * The page is deliberately self-contained and plainly styled: a file that opens
 * anywhere and reads like a document is the only reason to pick HTML over
 * Markdown.
 */
class HtmlExporter
{
    public function __construct(private readonly MarkdownExporter $markdown) {}

    /**
     * The full HTML document for one item.
     *
     * @param  ExportContext|null  $context  the archive around this document, if any
     */
    public function render(Task|Doc $item, ExportOptions $options, ?ExportContext $context = null): string
    {
        $markdown = $this->markdown->render($item, $options, $context);

        // Front matter is a Markdown convention; in a page it belongs in the
        // document as a definition list rather than as three stray dashes.
        [$frontMatter, $body] = $this->splitFrontMatter($markdown);

        $content = Str::markdown($body);

        return $this->page($item->reference.' · '.$item->title, $frontMatter, $content);
    }

    /**
     * Split a rendered document into its YAML front matter (as key => value, or
     * an empty list when there is none) and the Markdown that follows it.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private function splitFrontMatter(string $markdown): array
    {
        if (! str_starts_with($markdown, "---\n")) {
            return [[], $markdown];
        }

        $end = strpos($markdown, "\n---\n", 4);

        if ($end === false) {
            return [[], $markdown];
        }

        $fields = [];

        foreach (explode("\n", substr($markdown, 4, $end - 4)) as $line) {
            $separator = strpos($line, ': ');

            if ($separator !== false) {
                $fields[substr($line, 0, $separator)] = trim(substr($line, $separator + 2), '"');
            }
        }

        return [$fields, substr($markdown, $end + 5)];
    }

    /**
     * The metadata as a definition list, or nothing at all when the export was
     * asked to leave it out.
     *
     * @param  array<string, string>  $fields
     */
    private function metadata(array $fields): string
    {
        if ($fields === []) {
            return '';
        }

        $rows = '';

        foreach ($fields as $key => $value) {
            $rows .= '<dt>'.e($key).'</dt><dd>'.e($value).'</dd>';
        }

        return '<dl class="metadata">'.$rows.'</dl>';
    }

    /**
     * The page around the content: a complete document with its own styling, so
     * it renders the same wherever it is opened and needs nothing from us.
     *
     * @param  array<string, string>  $fields
     */
    private function page(string $title, array $fields, string $content): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html lang="{$this->language()}">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$this->escape($title)}</title>
        <style>{$this->styles()}</style>
        </head>
        <body>
        <main>
        {$this->metadata($fields)}
        {$content}
        </main>
        </body>
        </html>

        HTML;
    }

    private function escape(string $value): string
    {
        return e($value);
    }

    private function language(): string
    {
        return str_replace('_', '-', app()->getLocale());
    }

    /**
     * Enough styling to read comfortably: a measure, a readable typeface stack,
     * and quiet metadata. No fonts or stylesheets are fetched, so the page works
     * offline.
     */
    private function styles(): string
    {
        return <<<'CSS'
        :root { color-scheme: light dark; }
        body { margin: 0; padding: 2rem 1.5rem; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; line-height: 1.6; }
        main { max-width: 46rem; margin: 0 auto; }
        h1, h2, h3, h4, h5, h6 { line-height: 1.25; margin-top: 2rem; }
        h1 { margin-top: 0; }
        img { max-width: 100%; height: auto; }
        pre { overflow-x: auto; padding: 0.75rem 1rem; border-radius: 0.5rem; background: rgba(127, 127, 127, 0.12); }
        code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.9em; }
        pre code { font-size: 0.85em; }
        blockquote { margin: 1rem 0; padding: 0.25rem 0 0.25rem 1rem; border-left: 3px solid rgba(127, 127, 127, 0.4); }
        table { border-collapse: collapse; }
        th, td { padding: 0.35rem 0.75rem; border: 1px solid rgba(127, 127, 127, 0.35); text-align: left; }
        dl.metadata { display: grid; grid-template-columns: max-content 1fr; gap: 0.15rem 1rem; margin: 0 0 2rem; padding: 0 0 1rem; border-bottom: 1px solid rgba(127, 127, 127, 0.3); font-size: 0.9rem; }
        dl.metadata dt { font-weight: 600; }
        dl.metadata dd { margin: 0; }
        CSS;
    }
}
