<?php

namespace App\Concerns;

use App\Audit\AccessAudit;
use App\Enums\ExportFileLayout;
use App\Enums\ExportImageMode;
use App\Models\Comment;
use App\Models\Doc;
use App\Models\Task;
use App\Support\Export\ExportOptions;
use App\Support\Export\MarkdownBundle;
use App\Support\Export\MarkdownExporter;
use App\Support\Facades\Audit;
use App\Support\InlineAttachments;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
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
 * @property-read bool $exportHasComments
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
            $this->exportHasComments,
            $this->exportHasImages,
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
        $this->exportCanceled = (bool) ($remembered['canceled'] ?? false);
        $this->exportArchived = (bool) ($remembered['archived'] ?? false);
        $this->exportDrafts = (bool) ($remembered['drafts'] ?? false);
        $this->exportComments = (bool) ($remembered['comments'] ?? false);
        $this->exportDatePrefix = (bool) ($remembered['date_prefix'] ?? false);
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

        // The clipboard holds text, so a bundle has nowhere to go. The button is
        // hidden in that mode; this guards the action being called anyway.
        if ($options->bundle) {
            Flux::toast(text: __('An archive cannot go on the clipboard — download it instead.'), variant: 'warning');

            return;
        }

        $markdown = app(MarkdownExporter::class)->render($this->exportable(), $options);

        $this->recordExport($options);

        $this->dispatch('export-copied', markdown: $markdown);

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

        if ($options->bundle) {
            $bundle = app(MarkdownBundle::class);
            $contents = $bundle->zip($item, $options);
            $filename = $bundle->filename($item, $options);
            $type = 'application/zip';
        } else {
            $contents = app(MarkdownExporter::class)->render($item, $options);
            $filename = app(MarkdownExporter::class)->filename($item, $options->datePrefix);
            $type = 'text/markdown; charset=UTF-8';
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
            canceled: $this->exportCanceled,
            archived: $this->exportArchived,
            drafts: $this->exportDrafts,
            comments: $this->exportComments,
            bundle: $this->exportBundle && $this->exportDescendants && $this->exportSubtreeDepth > 0,
            layout: ExportFileLayout::tryFrom($this->exportLayout) ?? ExportFileLayout::Flat,
            datePrefix: $this->exportDatePrefix,
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

        Audit::record(AccessAudit::contentExported($this->exportable(), 'markdown', $options->toArray()));
    }
}
