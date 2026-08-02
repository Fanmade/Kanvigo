<?php

namespace App\Concerns;

use App\Audit\AccessAudit;
use App\Enums\ExportAttachmentMode;
use App\Enums\ExportFileLayout;
use App\Enums\ExportFormat;
use App\Enums\ExportImageMode;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Doc;
use App\Models\Task;
use App\Support\Export\ExportBundle;
use App\Support\Export\ExportOptions;
use App\Support\Export\ExportRenderer;
use App\Support\Export\MarkdownExporter;
use App\Support\Facades\Audit;
use App\Support\InlineAttachments;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The export dialog shared by the task and doc pages: renders the item as
 * Markdown and hands it over, either on the clipboard or as a file.
 *
 * A control is added by the task that implements what it controls, and every
 * control that applies to the subtree is shown only when the subtree has
 * something for it to do — no depth select without descendants, no drafts toggle
 * without a draft. Images, comments and other formats arrive with their own
 * tasks (KAN-455).
 *
 * Copying and downloading are the same act as far as the audit trail is
 * concerned: the content has left the instance either way, so both record one
 * `content_exported` event on the item.
 *
 * @property-read list<array{item: Task|Doc, level: int}> $exportableSubtree
 * @property-read int $exportSubtreeDepth
 * @property-read bool $exportHasCanceled
 * @property-read bool $exportHasArchived
 * @property-read bool $exportHasDrafts
 * @property-read bool $exportHasImages
 * @property-read bool $exportHasComments
 * @property-read bool $exportHasAttachments
 * @property-read bool $exportNeedsArchive
 */
trait ExportsContent
{
    public bool $exporting = false;

    /**
     * Whether the export carries the YAML front-matter block. On by default: the
     * metadata is what makes an exported file traceable back to the item.
     */
    public bool $exportMetadata = true;

    /**
     * Whether the export covers the item's whole subtree, and how much of it.
     * The depth is a string because "all" is a real choice, not a number: a
     * subtree that grows deeper later still exports in full.
     */
    public bool $exportDescendants = false;

    public string $exportDepth = 'all';

    /**
     * The hand-picked selection, as "task:12" keys, or null while the depth
     * select is doing the choosing. Ticking a parent ticks its subtree; unticking
     * one leaves what is below it alone, and the renderer promotes the survivors.
     *
     * @var list<string>|null
     */
    public ?array $exportOnly = null;

    public bool $exportCanceled = false;

    public bool $exportArchived = false;

    public bool $exportDrafts = false;

    /**
     * Whether the discussion travels with the content. Off by default: a comment
     * thread is the conversation about the work, not the work itself.
     */
    public bool $exportComments = false;

    /**
     * How images inside the exported content travel — an {@see ExportImageMode}
     * value. Held as a string because it is bound to a select.
     */
    public string $exportImages = 'embed';

    /**
     * Whether the attached files travel with the content — an
     * {@see ExportAttachmentMode} value.
     */
    public string $exportAttachments = 'none';

    /**
     * What the export is written as — an {@see ExportFormat} value.
     */
    public string $exportFormat = 'markdown';

    /**
     * Whether the export is one file per item, delivered as an archive, and how
     * that archive arranges them. Only meaningful with descendants included —
     * a bundle of one file is just a file.
     */
    public bool $exportBundle = false;

    public string $exportLayout = 'flat';

    /**
     * Whether the download filename carries the date it was taken. Affects the
     * file only, so it is offered beside Download and means nothing for Copy.
     */
    public bool $exportDatePrefix = false;

    /**
     * The item this component exports.
     */
    abstract protected function exportable(): Task|Doc;

    /**
     * Everything below the exported item that could be exported, whatever the
     * current options say — the basis for deciding which controls apply at all.
     * A control for something the subtree does not contain is a control nobody
     * can use.
     *
     * @return list<array{item: Task|Doc, level: int}>
     */
    #[Computed]
    public function exportableSubtree(): array
    {
        return app(MarkdownExporter::class)->subtree(
            $this->exportable(),
            new ExportOptions(descendants: true, canceled: true, archived: true, drafts: true),
        );
    }

    /**
     * How many levels of descendants the item has (0 when it has none), which is
     * both the "Include descendants" condition and the top of the depth select.
     */
    #[Computed]
    public function exportSubtreeDepth(): int
    {
        return (int) max([0, ...array_column($this->exportableSubtree, 'level')]);
    }

    /**
     * Whether the subtree holds any canceled task — without one, the "Include
     * canceled" toggle would change nothing.
     */
    #[Computed]
    public function exportHasCanceled(): bool
    {
        foreach ($this->exportableSubtree as $entry) {
            if ($entry['item'] instanceof Task && $entry['item']->isCanceled()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the subtree holds an archived task — the same condition as the
     * canceled toggle, for work that aged off the board rather than being
     * abandoned.
     */
    #[Computed]
    public function exportHasArchived(): bool
    {
        foreach ($this->exportableSubtree as $entry) {
            if ($entry['item'] instanceof Task && $entry['item']->isArchived()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the subtree holds a draft doc this viewer may see. A draft they
     * cannot see must not be advertised by the toggle appearing.
     */
    #[Computed]
    public function exportHasDrafts(): bool
    {
        foreach ($this->exportableSubtree as $entry) {
            $item = $entry['item'];

            if ($item instanceof Doc && ! $item->is_public && Gate::allows('view', $item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether what is about to be exported contains any image at all — without
     * one, how images travel is not a question worth asking. Checked against the
     * export as currently configured, so turning descendants on can bring the
     * control into view.
     */
    #[Computed]
    public function exportHasImages(): bool
    {
        $documents = [$this->exportable()];

        if ($this->exportDescendants) {
            foreach ($this->exportableSubtree as $entry) {
                $documents[] = $entry['item'];
            }
        }

        foreach ($documents as $item) {
            $html = $item instanceof Task ? $item->description : $item->body;

            if (InlineAttachments::referencedIds($html) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether anything in the export has a file attached to it — without one,
     * offering to carry the files is offering nothing.
     */
    #[Computed]
    public function exportHasAttachments(): bool
    {
        $items = [$this->exportable()];

        if ($this->exportDescendants) {
            foreach ($this->exportableSubtree as $entry) {
                $items[] = $entry['item'];
            }
        }

        $idsByType = [];

        foreach ($items as $item) {
            $idsByType[$item->getMorphClass()][] = $item->getKey();
        }

        foreach ($idsByType as $type => $ids) {
            $exists = Attachment::query()
                ->where('attachable_type', $type)
                ->whereIn('attachable_id', $ids)
                ->where('is_inline', false)
                ->exists();

            if ($exists) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether anything in the export has been commented on — the condition for
     * offering to include the discussion.
     */
    #[Computed]
    public function exportHasComments(): bool
    {
        $items = [$this->exportable()];

        if ($this->exportDescendants) {
            foreach ($this->exportableSubtree as $entry) {
                $items[] = $entry['item'];
            }
        }

        // One existence check per kind of item, rather than one per item: a wide
        // subtree must not turn opening the dialog into a query storm.
        $idsByType = [];

        foreach ($items as $item) {
            $idsByType[$item->getMorphClass()][] = $item->getKey();
        }

        foreach ($idsByType as $type => $ids) {
            if (Comment::query()->where('commentable_type', $type)->whereIn('commentable_id', $ids)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the export as configured has to arrive as an archive — one file
     * per item, or images travelling as files. The clipboard is withdrawn in
     * that case, because an archive cannot go on it.
     */
    #[Computed]
    public function exportNeedsArchive(): bool
    {
        return ($this->exportBundle && $this->exportDescendants)
            || $this->exportImages === ExportImageMode::Files->value
            || $this->exportAttachments === ExportAttachmentMode::Files->value;
    }

    /**
     * Whether the viewer may export at all. Deliberately not granted to viewers —
     * taking content out of the instance is more than reading it in place.
     */
    #[Computed]
    public function canExport(): bool
    {
        return Gate::allows('export-content', $this->exportable()->project);
    }

    /**
     * Switch to picking items by hand, starting from whatever the depth select
     * currently covers — the quick path is where the precise one begins.
     */
    public function startPickingExportItems(): void
    {
        $this->exportOnly = array_map(
            fn (array $entry): string => app(MarkdownExporter::class)->selectionKey($entry['item']),
            app(MarkdownExporter::class)->subtree($this->exportable(), $this->exportOptions()),
        );
    }

    /**
     * Back to the depth select, forgetting the hand-picked set.
     */
    public function stopPickingExportItems(): void
    {
        $this->exportOnly = null;
    }

    /**
     * Tick or untick one item — and, when ticking, everything below it, which is
     * how people prune a tree.
     */
    public function toggleExportItem(string $key): void
    {
        $selected = array_flip($this->exportOnly ?? []);
        $subtree = $this->exportableSubtree;
        $exporter = app(MarkdownExporter::class);

        $keys = [$key, ...$this->descendantKeysOf($key, $subtree, $exporter)];

        if (isset($selected[$key])) {
            foreach ($keys as $each) {
                unset($selected[$each]);
            }
        } else {
            foreach ($keys as $each) {
                $selected[$each] = true;
            }
        }

        $this->exportOnly = array_keys($selected);
    }

    /**
     * The keys of everything nested under one item of the subtree.
     *
     * @param  list<array{item: Task|Doc, level: int}>  $subtree
     * @return list<string>
     */
    private function descendantKeysOf(string $key, array $subtree, MarkdownExporter $exporter): array
    {
        $children = [];

        foreach ($subtree as $entry) {
            $item = $entry['item'];
            $parentKey = $item->parent_id === null
                ? null
                : ($item instanceof Task ? 'task' : 'doc').':'.$item->parent_id;

            if ($parentKey === $key) {
                $childKey = $exporter->selectionKey($item);
                $children = [$childKey, ...$children, ...$this->descendantKeysOf($childKey, $subtree, $exporter)];
            }
        }

        return $children;
    }

    /**
     * Export straight away with the options this user last chose, skipping the
     * dialog — the palette's zero-click path (KAN-482).
     *
     * Copy is the palette-shaped answer, so that is the default. When the
     * remembered options need an archive there is nothing to put on a clipboard,
     * so it downloads instead and says so rather than quietly doing something
     * other than what was asked.
     */
    #[On('quick-export')]
    public function quickExport(): ?StreamedResponse
    {
        $this->authorize('export-content', $this->exportable()->project);

        $this->restoreExportOptions();

        if ($this->exportOptions()->needsArchive()) {
            Flux::toast(text: __('An archive cannot go on the clipboard — downloaded instead.'), variant: 'warning');

            return $this->downloadExport();
        }

        $this->copyExport();

        return null;
    }

    public function startExport(): void
    {
        $this->authorize('export-content', $this->exportable()->project);

        // The subtree may have changed since the page loaded, so the conditional
        // controls are decided fresh each time the dialog opens.
        unset(
            $this->exportableSubtree,
            $this->exportSubtreeDepth,
            $this->exportHasCanceled,
            $this->exportHasArchived,
            $this->exportHasDrafts,
            $this->exportHasComments,
            $this->exportHasImages,
            $this->exportHasAttachments,
        );

        $this->restoreExportOptions();

        $this->exporting = true;
    }

    /**
     * Fill the dialog with what this user chose last time. A remembered choice
     * is only restored where it still applies: a depth deeper than this subtree
     * clamps to what is there, and "All" stays "All" — which is the whole reason
     * it is a named option rather than a stored number. Nothing is exported
     * until the user acts, so the restored state is always visible first.
     */
    private function restoreExportOptions(): void
    {
        $remembered = Auth::user()?->preference(ExportOptions::PREFERENCE_KEY);

        if (! is_array($remembered)) {
            return;
        }

        $this->exportMetadata = (bool) ($remembered['metadata'] ?? $this->exportMetadata);
        $this->exportDescendants = (bool) ($remembered['descendants'] ?? false) && $this->exportSubtreeDepth > 0;
        // A hand-picked set belongs to the item it was picked on, so it is never
        // restored: the dialog reopens on the quick path.
        $this->exportOnly = null;
        $this->exportCanceled = (bool) ($remembered['canceled'] ?? false);
        $this->exportArchived = (bool) ($remembered['archived'] ?? false);
        $this->exportDrafts = (bool) ($remembered['drafts'] ?? false);
        $this->exportComments = (bool) ($remembered['comments'] ?? false);
        $this->exportDatePrefix = (bool) ($remembered['date_prefix'] ?? false);

        $format = ExportFormat::tryFrom((string) ($remembered['format'] ?? ''));
        $this->exportFormat = ($format ?? ExportFormat::Markdown)->value;

        $attachments = ExportAttachmentMode::tryFrom((string) ($remembered['attachments'] ?? ''));
        $this->exportAttachments = ($attachments ?? ExportAttachmentMode::None)->value;
        $this->exportBundle = (bool) ($remembered['bundle'] ?? false) && $this->exportDescendants;

        $layout = ExportFileLayout::tryFrom((string) ($remembered['layout'] ?? ''));
        $this->exportLayout = ($layout ?? ExportFileLayout::Flat)->value;

        $images = ExportImageMode::tryFrom((string) ($remembered['images'] ?? ''));
        $this->exportImages = ($images ?? ExportImageMode::Embed)->value;

        $depth = $remembered['depth'] ?? 'all';
        $this->exportDepth = $depth === 'all' || (int) $depth > $this->exportSubtreeDepth
            ? 'all'
            : (string) (int) $depth;
    }

    /**
     * Remember the options this export was taken with, so the next one starts
     * where this one left off.
     */
    private function rememberExportOptions(ExportOptions $options): void
    {
        Auth::user()?->setPreference(ExportOptions::PREFERENCE_KEY, $options->toArray());
    }

    /**
     * Put the rendered Markdown on the clipboard. The write itself has to happen
     * in the browser, so the markup is dispatched to a listener on the modal;
     * the server keeps the authorization and the audit event.
     */
    public function copyExport(): void
    {
        $options = $this->exportOptions();

        // The clipboard holds text, so an archive has nowhere to go. The button
        // is hidden in that case; this guards the action being called anyway.
        if ($options->needsArchive()) {
            Flux::toast(text: __('An archive cannot go on the clipboard — download it instead.'), variant: 'warning');

            return;
        }

        $document = app(ExportRenderer::class)->render($this->exportable(), $options);

        $this->recordExport($options);

        $this->dispatch('export-copied', markdown: $document);

        Flux::toast(text: __('Copied to clipboard.'), variant: 'success');

        $this->exporting = false;
    }

    /**
     * Download the export: one Markdown file, or a ZIP holding one file per item
     * when the bundle option is on.
     */
    public function downloadExport(): StreamedResponse
    {
        $item = $this->exportable();
        $options = $this->exportOptions();

        if ($options->needsArchive()) {
            $bundle = app(ExportBundle::class);
            $contents = $bundle->zip($item, $options);
            $filename = $bundle->filename($item, $options);
            $type = 'application/zip';
        } else {
            $renderer = app(ExportRenderer::class);
            $contents = $renderer->render($item, $options);
            $filename = $renderer->filename($item, $options);
            $type = $options->format->mimeType();
        }

        $this->recordExport($options);

        $this->exporting = false;

        return response()->streamDownload(
            static function () use ($contents): void {
                echo $contents;
            },
            $filename,
            ['Content-Type' => $type],
        );
    }

    /**
     * The options the dialog currently describes, after authorizing the export.
     * Both entry points start here, so neither can skip the permission check.
     */
    private function exportOptions(): ExportOptions
    {
        $this->authorize('export-content', $this->exportable()->project);

        return new ExportOptions(
            metadata: $this->exportMetadata,
            descendants: $this->exportDescendants && $this->exportSubtreeDepth > 0,
            depth: $this->exportDepth === 'all' ? null : (int) $this->exportDepth,
            only: $this->exportDescendants ? $this->exportOnly : null,
            canceled: $this->exportCanceled,
            archived: $this->exportArchived,
            drafts: $this->exportDrafts,
            comments: $this->exportComments,
            bundle: $this->exportBundle && $this->exportDescendants && $this->exportSubtreeDepth > 0,
            layout: ExportFileLayout::tryFrom($this->exportLayout) ?? ExportFileLayout::Flat,
            datePrefix: $this->exportDatePrefix,
            format: ExportFormat::tryFrom($this->exportFormat) ?? ExportFormat::Markdown,
            attachments: ExportAttachmentMode::tryFrom($this->exportAttachments) ?? ExportAttachmentMode::None,
            images: ExportImageMode::tryFrom($this->exportImages) ?? ExportImageMode::Embed,
        );
    }

    /**
     * Record that an export left the instance, and remember how it was shaped.
     * Copying and downloading are the same event: the content is gone either way.
     */
    private function recordExport(ExportOptions $options): void
    {
        $this->rememberExportOptions($options);

        Audit::record(AccessAudit::contentExported($this->exportable(), $options->format->value, $options->toArray()));
    }
}
