<?php

namespace App\Support\Export;

use App\Enums\ExportFileLayout;
use App\Models\Doc;
use App\Models\Task;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Writes an item and its subtree as one Markdown file each, packed into a ZIP.
 *
 * The single-file export flattens a tree into one document; a bundle keeps the
 * tree, expressing it through file names or directories (the reader chooses via
 * {@see ExportFileLayout}). Cross-references between two items that both travel
 * in the archive are rewritten to point at the file rather than at this
 * instance, so the bundle is readable by someone who cannot reach the server —
 * which is the only reason to prefer it over a single document.
 */
class MarkdownBundle
{
    public function __construct(private readonly MarkdownExporter $exporter) {}

    /**
     * The bundle's files as path => Markdown, in the order the tree reads.
     *
     * @return array<string, string>
     */
    public function files(Task|Doc $root, ExportOptions $options): array
    {
        $entries = [
            ['item' => $root, 'level' => 0],
            ...($options->descendants ? $this->exporter->subtree($root, $options) : []),
        ];

        $paths = $this->paths($entries, $options);
        $single = $options->forSingleItem();
        $files = [];

        foreach ($entries as $entry) {
            $item = $entry['item'];
            $path = $paths[$this->key($item)];

            $files[$path] = $this->exporter->render($item, $single, $this->linksFrom($path, $paths));
        }

        return $files;
    }

    /**
     * The archive itself, as bytes.
     */
    public function zip(Task|Doc $root, ExportOptions $options): string
    {
        $archive = new ZipArchive;
        $path = tempnam(sys_get_temp_dir(), 'kanvigo-export');

        if ($path === false || $archive->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the export archive.');
        }

        foreach ($this->files($root, $options) as $file => $contents) {
            $archive->addFromString($file, $contents);
        }

        $archive->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /**
     * The archive's own name: the same stem the single-file download uses, so an
     * export is recognisable whichever shape it arrives in.
     */
    public function filename(Task|Doc $root, ExportOptions $options): string
    {
        return Str::replaceLast('.md', '.zip', $this->exporter->filename($root, $options->datePrefix));
    }

    /**
     * Where each item's file goes. A flat layout puts everything at the root; a
     * nested one gives any item with children a directory of its own, with its
     * own text in `index.md` beside them.
     *
     * The date prefix names the archive and, in the nested layout, the single
     * folder at its top. Everything below that stays plain: once the thing you
     * open is dated, repeating the date on every file inside it is noise.
     *
     * @param  list<array{item: Task|Doc, level: int}>  $entries
     * @return array<string, string>
     */
    private function paths(array $entries, ExportOptions $options): array
    {
        $root = $entries[0]['item'] ?? null;
        $names = [];

        foreach ($entries as $entry) {
            $names[$this->key($entry['item'])] = $this->exporter->filename($entry['item']);
        }

        if ($options->layout === ExportFileLayout::Flat) {
            return $names;
        }

        $hasChildren = [];

        foreach ($entries as $entry) {
            $parent = $entry['item']->parent_id;

            if ($parent !== null) {
                $hasChildren[$parent] = true;
            }
        }

        $directories = [];
        $paths = [];

        foreach ($entries as $entry) {
            $item = $entry['item'];
            $key = $this->key($item);
            $prefix = $directories[$item->parent_id] ?? '';

            if (isset($hasChildren[$item->getKey()])) {
                $folder = Str::replaceLast('.md', '', $names[$key]);

                if ($options->datePrefix && $item === $root) {
                    $folder = now()->format('Y-m-d').'_'.$folder;
                }

                $directory = $prefix.$folder.'/';
                $directories[$item->getKey()] = $directory;
                $paths[$key] = $directory.'index.md';

                continue;
            }

            $paths[$key] = $prefix.$names[$key];
        }

        return $paths;
    }

    /**
     * The in-bundle link targets as seen from one file: every other item's path,
     * made relative to the directory this file sits in.
     *
     * @param  array<string, string>  $paths
     * @return array<string, string>
     */
    private function linksFrom(string $from, array $paths): array
    {
        $links = [];

        foreach ($paths as $key => $path) {
            $links[$key] = $this->relative($from, $path);
        }

        return $links;
    }

    /**
     * A path to $target expressed from the directory holding $from.
     */
    private function relative(string $from, string $target): string
    {
        $fromParts = explode('/', $from);
        array_pop($fromParts);
        $targetParts = explode('/', $target);
        $file = array_pop($targetParts);

        while ($fromParts !== [] && $targetParts !== [] && $fromParts[0] === $targetParts[0]) {
            array_shift($fromParts);
            array_shift($targetParts);
        }

        return str_repeat('../', count($fromParts)).implode('/', [...$targetParts, $file]);
    }

    /**
     * The key an item is known by in the link map — the same shape the reference
     * markup carries, so the converter can look a target up directly.
     */
    private function key(Task|Doc $item): string
    {
        return ($item instanceof Task ? 'task' : 'doc').':'.$item->getKey();
    }
}
