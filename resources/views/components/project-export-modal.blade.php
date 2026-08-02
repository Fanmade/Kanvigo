{{--
    The whole-project export dialog, rendered by any component using the
    ExportsProject concern — it binds to that trait's `projectExport*` properties.

    Narrower than the per-item dialog on purpose: a project export is always
    every level and always one file per item, so there is nothing to decide about
    descendants or bundling, and nothing to put on a clipboard.
--}}
<flux:modal wire:model.self="exportingProject" class="md:w-96" data-test="project-export-modal">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Export project') }}</flux:heading>
            <flux:text class="mt-2"> {{ __('Every task and doc as its own file, in one archive.') }} </flux:text>
        </div>

        <flux:select wire:model.live="projectExportFormat" :label="__('Format')" data-test="project-export-format">
            @foreach (\App\Enums\ExportFormat::cases() as $format)
                <flux:select.option :value="$format->value">{{ $format->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model="projectExportLayout" :label="__('Files')" data-test="project-export-layout">
            @foreach (\App\Enums\ExportFileLayout::cases() as $layout)
                <flux:select.option :value="$layout->value">{{ $layout->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <div class="flex flex-col gap-3">
            <flux:checkbox
                wire:model="projectExportMetadata"
                :label="__('Include metadata')"
                data-test="project-export-metadata"
            />
            <flux:checkbox
                wire:model="projectExportComments"
                :label="__('Include comments')"
                data-test="project-export-comments"
            />
            <flux:checkbox
                wire:model.live="projectExportCanceled"
                :label="__('Include canceled')"
                data-test="project-export-canceled"
            />
            <flux:checkbox
                wire:model.live="projectExportArchived"
                :label="__('Include archived')"
                data-test="project-export-archived"
            />
            <flux:checkbox
                wire:model.live="projectExportDrafts"
                :label="__('Include drafts')"
                data-test="project-export-drafts"
            />
            <flux:checkbox
                wire:model="projectExportDatePrefix"
                :label="__('Prefix the filename with the date')"
                data-test="project-export-date-prefix"
            />
        </div>

        <flux:select wire:model="projectExportImages" :label="__('Images')" data-test="project-export-images">
            @foreach (\App\Enums\ExportImageMode::cases() as $mode)
                <flux:select.option :value="$mode->value">{{ $mode->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select
            wire:model="projectExportAttachments"
            :label="__('Attachments')"
            data-test="project-export-attachments"
        >
            @foreach (\App\Enums\ExportAttachmentMode::cases() as $mode)
                <flux:select.option :value="$mode->value">{{ $mode->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        {{-- The archive is built in the request, so the size is worth seeing
             before committing to it. Counted only while the dialog is open:
             walking every subtree is not something the page should pay for on
             every render. --}}
        @if ($this->exportingProject)
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400" data-test="project-export-count">
                {{ trans_choice(':count item|:count items', $this->exportItemCount, ['count' => $this->exportItemCount]) }}
            </flux:text>
        @endif

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button
                variant="primary"
                icon="arrow-down-tray"
                wire:click="downloadProjectExport"
                data-test="project-export-download"
            >{{ __('Download') }}</flux:button>
        </div>
    </div>
</flux:modal>
