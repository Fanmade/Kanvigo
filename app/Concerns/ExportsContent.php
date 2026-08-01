<?php

namespace App\Concerns;

use App\Audit\AccessAudit;
use App\Models\Doc;
use App\Models\Task;
use App\Support\Export\ExportOptions;
use App\Support\Export\MarkdownExporter;
use App\Support\Facades\Audit;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The export dialog shared by the task and doc pages: renders the item as
 * Markdown and hands it over, either on the clipboard or as a file.
 *
 * The MVP shows one option — whether to include the YAML front-matter — because
 * a control is added by the task that implements what it controls; descendants,
 * images, comments and other formats each arrive with their own (KAN-455).
 *
 * Copying and downloading are the same act as far as the audit trail is
 * concerned: the content has left the instance either way, so both record one
 * `content_exported` event on the item.
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
     * The item this component exports.
     */
    abstract protected function exportable(): Task|Doc;

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

        $options = new ExportOptions(metadata: $this->exportMetadata);

        Audit::record(AccessAudit::contentExported($item, 'markdown', $options->toArray()));

        return app(MarkdownExporter::class)->render($item, $options);
    }
}
