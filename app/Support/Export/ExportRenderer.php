<?php

namespace App\Support\Export;

use App\Enums\ExportFormat;
use App\Models\Doc;
use App\Models\Task;

/**
 * Picks the renderer an export's format asks for.
 *
 * This is the whole "format seam", and deliberately so: the two formats agree
 * about everything except the last step and the file extension, so a `match` is
 * the honest amount of abstraction. An interface would be a guess about what a
 * third format needs — and the one already known to disagree (PDF, which is
 * bytes and cannot go on a clipboard) is not built yet.
 * See docs/adr/0002-export-has-no-format-abstraction.md.
 */
class ExportRenderer
{
    public function __construct(
        private readonly MarkdownExporter $markdown,
        private readonly HtmlExporter $html,
    ) {}

    /**
     * The exported document for one item.
     *
     * @param  array<string, string>  $localLinks  in-bundle link targets
     * @param  ExportImages|null  $images  shared image decisions, when an archive
     *                                     renders several documents
     */
    public function render(Task|Doc $item, ExportOptions $options, array $localLinks = [], ?ExportImages $images = null): string
    {
        return match ($options->format) {
            ExportFormat::Html => $this->html->render($item, $options, $localLinks, $images),
            ExportFormat::Markdown => $this->markdown->render($item, $options, $localLinks, $images),
        };
    }

    /**
     * One set of image decisions for a whole archive, so the same picture is
     * stored once however many documents show it.
     *
     * @param  list<Task|Doc>  $items
     */
    public function imagesFor(array $items, ExportOptions $options): ExportImages
    {
        return $this->markdown->imagesFor($items, $this->markdown->commentsForItems($items, $options), $options);
    }

    /**
     * The file this export is downloaded as, extension included.
     */
    public function filename(Task|Doc $item, ExportOptions $options): string
    {
        return $this->markdown->filename($item, $options->datePrefix, $options->format);
    }

    /**
     * The subtree an export covers — the same walk whatever the format.
     *
     * @return list<array{item: Task|Doc, level: int}>
     */
    public function subtree(Task|Doc $root, ExportOptions $options): array
    {
        return $this->markdown->subtree($root, $options);
    }
}
