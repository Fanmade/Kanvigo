<?php

namespace App\Concerns;

use App\Audit\AccessAudit;
use App\Enums\ExportAttachmentMode;
use App\Enums\ExportFileLayout;
use App\Enums\ExportFormat;
use App\Enums\ExportImageMode;
use App\Models\Project;
use App\Support\Export\Exceptions\ProjectTooLargeToExport;
use App\Support\Export\ExportOptions;
use App\Support\Export\ProjectExport;
use App\Support\Facades\Audit;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exporting a whole project: every top-level task with its subtree and every doc
 * the viewer may see, as one archive.
 *
 * Deliberately a narrower dialog than the per-item one. There is nothing to
 * choose about descendants (a project export is its trees) and nothing to copy
 * to a clipboard (it is always an archive), so what remains is what the files
 * say and what travels with them. The options are the same value object and the
 * same remembered preference as everywhere else.
 *
 * Held to `export-project`, not `export-content`: taking a board out in one
 * archive is a different act from exporting the task you are reading.
 *
 * @property-read bool $canExportProject
 * @property-read int $exportItemCount
 */
trait ExportsProject
{
    public bool $exportingProject = false;

    public bool $projectExportMetadata = true;

    public bool $projectExportComments = false;

    public bool $projectExportCanceled = false;

    public bool $projectExportArchived = false;

    public bool $projectExportDrafts = false;

    public string $projectExportLayout = 'nested';

    public string $projectExportFormat = 'markdown';

    public string $projectExportImages = 'files';

    public string $projectExportAttachments = 'none';

    public bool $projectExportDatePrefix = false;

    /**
     * The project this component exports.
     */
    abstract protected function exportableProject(): Project;

    #[Computed]
    public function canExportProject(): bool
    {
        return Gate::allows('export-project', $this->exportableProject());
    }

    /**
     * How many items the archive would hold, shown before anyone commits to it —
     * the same count the size guard applies.
     *
     * Read only while the dialog is open: it walks every tree in the project, so
     * a page that merely *could* export must not pay for it on each render.
     */
    #[Computed]
    public function exportItemCount(): int
    {
        return app(ProjectExport::class)->itemCount($this->exportableProject(), $this->projectExportOptions());
    }

    public function startProjectExport(): void
    {
        $this->authorize('export-project', $this->exportableProject());

        unset($this->exportItemCount);

        $this->exportingProject = true;
    }

    /**
     * Build and download the archive, or say plainly that the project is too
     * large for one request rather than timing out halfway through.
     */
    public function downloadProjectExport(): ?StreamedResponse
    {
        $project = $this->exportableProject();
        $this->authorize('export-project', $project);

        $options = $this->projectExportOptions();
        $export = app(ProjectExport::class);

        try {
            $contents = $export->zip($project, $options);
        } catch (ProjectTooLargeToExport $tooLarge) {
            Flux::toast(
                text: __('This project holds :count items, more than the :limit an export can cover in one go.', [
                    'count' => $tooLarge->count,
                    'limit' => $tooLarge->limit,
                ]),
                variant: 'danger',
            );

            return null;
        }

        Auth::user()?->setPreference(ExportOptions::PREFERENCE_KEY.'.project', $options->toArray());

        Audit::record(AccessAudit::projectExported($project, $options->toArray()));

        $this->exportingProject = false;

        $filename = $export->filename($project, $options);

        return response()->streamDownload(
            static function () use ($contents): void {
                echo $contents;
            },
            $filename,
            ['Content-Type' => 'application/zip'],
        );
    }

    /**
     * What the dialog currently describes. Descendants and bundling are not
     * offered: a project export is always every level, one file per item.
     */
    private function projectExportOptions(): ExportOptions
    {
        return new ExportOptions(
            metadata: $this->projectExportMetadata,
            descendants: true,
            canceled: $this->projectExportCanceled,
            archived: $this->projectExportArchived,
            drafts: $this->projectExportDrafts,
            comments: $this->projectExportComments,
            bundle: true,
            layout: ExportFileLayout::tryFrom($this->projectExportLayout) ?? ExportFileLayout::Nested,
            datePrefix: $this->projectExportDatePrefix,
            format: ExportFormat::tryFrom($this->projectExportFormat) ?? ExportFormat::Markdown,
            attachments: ExportAttachmentMode::tryFrom($this->projectExportAttachments) ?? ExportAttachmentMode::None,
            images: ExportImageMode::tryFrom($this->projectExportImages) ?? ExportImageMode::Files,
        );
    }
}
