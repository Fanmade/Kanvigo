<?php

namespace App\Concerns;

use App\Audit\AccessAudit;
use App\Enums\ExportImageMode;
use App\Models\Doc;
use App\Models\Task;
use App\Support\Export\ExportOptions;
use App\Support\Export\MarkdownExporter;
use App\Support\Facades\Audit;
use App\Support\InlineAttachments;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
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

    public bool $exportCanceled = false;

    public bool $exportArchived = false;

    public bool $exportDrafts = false;

    /**
     * How images inside the exported content travel — an {@see ExportImageMode}
     * value. Held as a string because it is bound to a select.
     */
    public string $exportImages = 'embed';

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
     * Whether the viewer may export at all. Deliberately not granted to viewers —
     * taking content out of the instance is more than reading it in place.
     */
    #[Computed]
    public function canExport(): bool
    {
        return Gate::allows('export-content', $this->exportable()->project);
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
        );

        $this->exporting = true;
    }

    /**
     * Put the rendered Markdown on the clipboard. The write itself has to happen
     * in the browser, so the markup is dispatched to a listener on the modal;
     * the server keeps the authorization and the audit event.
     */
    public function copyExport(): void
    {
        $markdown = $this->renderExport();

        $this->dispatch('export-copied', markdown: $markdown);

        Flux::toast(text: __('Copied to clipboard.'), variant: 'success');

        $this->exporting = false;
    }

    /**
     * Download the rendered Markdown as a file named after the item.
     */
    public function downloadExport(): StreamedResponse
    {
        $markdown = $this->renderExport();
        $filename = app(MarkdownExporter::class)->filename($this->exportable());

        $this->exporting = false;

        return response()->streamDownload(
            static function () use ($markdown): void {
                echo $markdown;
            },
            $filename,
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    }

    /**
     * Authorize, render and record one export. Both entry points go through
     * here, so neither can skip the permission check or the audit event.
     */
    private function renderExport(): string
    {
        $item = $this->exportable();
        $this->authorize('export-content', $item->project);

        $options = new ExportOptions(
            metadata: $this->exportMetadata,
            descendants: $this->exportDescendants && $this->exportSubtreeDepth > 0,
            depth: $this->exportDepth === 'all' ? null : (int) $this->exportDepth,
            canceled: $this->exportCanceled,
            archived: $this->exportArchived,
            drafts: $this->exportDrafts,
            images: ExportImageMode::tryFrom($this->exportImages) ?? ExportImageMode::Embed,
        );

        Audit::record(AccessAudit::contentExported($item, 'markdown', $options->toArray()));

        return app(MarkdownExporter::class)->render($item, $options);
    }
}
