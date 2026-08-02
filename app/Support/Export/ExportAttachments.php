<?php

namespace App\Support\Export;

use App\Models\Attachment;
use App\Models\Doc;
use App\Models\Task;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * Carries the files attached to the exported items into the archive, and gives
 * each document a section listing them.
 *
 * An inline image is already in the text and only needs somewhere to point
 * ({@see ExportImages}); an attachment is mentioned nowhere, so writing it into
 * the archive without listing it would produce files no reader ever finds. That
 * listing is what this class adds, and why attachments are a separate choice
 * rather than a flag on the image mode.
 *
 * One instance renders one export: the files are collected as it goes, and each
 * attachment is stored once however many documents mention it.
 */
class ExportAttachments
{
    /**
     * Where the attachment files live inside the archive.
     */
    public const string DIRECTORY = 'attachments';

    /**
     * The attachments of every exported item, keyed "type:id".
     *
     * @var array<string, list<Attachment>>
     */
    private array $byItem = [];

    /**
     * The files to write, as archive path => bytes.
     *
     * @var array<string, string>
     */
    private array $files = [];

    /**
     * The archive paths already assigned, keyed by attachment id.
     *
     * @var array<int, string>
     */
    private array $stored = [];

    /**
     * How to get from the document being rendered back to the archive root.
     */
    private string $basePath = '';

    /**
     * Load the attachments of every item in the export — one query per kind of
     * item — skipping the inline images, which travel as part of the content.
     *
     * @param  list<Task|Doc>  $items
     */
    public function prepare(array $items): void
    {
        $idsByType = [];

        foreach ($items as $item) {
            $idsByType[$item->getMorphClass()][] = $item->getKey();
        }

        foreach ($idsByType as $type => $ids) {
            $attachments = Attachment::query()
                ->where('attachable_type', $type)
                ->whereIn('attachable_id', $ids)
                ->where('is_inline', false)
                ->orderBy('name')
                ->get();

            foreach ($attachments as $attachment) {
                $this->byItem[$type.':'.$attachment->attachable_id][] = $attachment;
            }
        }
    }

    /**
     * Point the next rendered document at the archive root.
     */
    public function relativeTo(string $basePath): void
    {
        $this->basePath = $basePath;
    }

    /**
     * The attachment files to write into the archive, as path => bytes.
     *
     * @return array<string, string>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * The Markdown section listing one item's attachments — its heading a level
     * below the item's own, then a link and a size per file. Nothing at all for
     * an item with no attachments, so a subtree export grows no empty sections.
     *
     * @return list<string>
     */
    public function sectionFor(Task|Doc $item, int $level): array
    {
        $attachments = $this->byItem[$item->getMorphClass().':'.$item->getKey()] ?? [];

        if ($attachments === []) {
            return [];
        }

        $lines = [];

        foreach ($attachments as $attachment) {
            $path = $this->storedFile($attachment);
            $size = Number::fileSize((int) $attachment->size);

            $lines[] = $path === null
                // The row survives its file: say the name and that it is missing,
                // rather than linking to a path the archive does not contain.
                ? '- '.$attachment->name.' *'.__('file not exported').'*'
                : '- ['.$attachment->name.']('.$this->basePath.$path.') — '.$size;
        }

        return [
            str_repeat('#', min($level + 1, 6)).' '.__('Attachments'),
            implode("\n", $lines),
        ];
    }

    /**
     * The archive path for an attachment, remembering its bytes for the writer.
     * The id prefixes the name so two files of the same name cannot collide, and
     * a file attached to two items is stored once.
     */
    private function storedFile(Attachment $attachment): ?string
    {
        $id = (int) $attachment->getKey();

        if (isset($this->stored[$id])) {
            return $this->stored[$id];
        }

        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->path)) {
            return null;
        }

        $name = self::DIRECTORY.'/'.$id.'-'.Str::of((string) $attachment->name)
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9._-]+/', '-')
            ->replaceMatches('/\.{2,}/', '-')
            ->trim('-.')
            ->lower()
            ->toString();

        $this->files[$name] = (string) $disk->get($attachment->path);

        return $this->stored[$id] = $name;
    }
}
