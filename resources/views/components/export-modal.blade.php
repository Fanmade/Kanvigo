{{--
    The export dialog for a task or doc, rendered by any component using the
    ExportsContent concern — it binds to that trait's `exporting` and
    `exportMetadata` properties.

    Copying happens in the browser (only it can reach the clipboard), so the
    server dispatches the rendered Markdown and this listener writes it; the
    permission check and the audit event stay on the server side.
--}}
<flux:modal
    wire:model.self="exporting"
    class="md:w-96"
    data-test="export-modal"
    x-on:export-copied.window="navigator.clipboard.writeText($event.detail.markdown)"
>
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Export') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Take this item with you as a file.') }}</flux:text>
        </div>

        <flux:select wire:model.live="exportFormat" :label="__('Format')" data-test="export-format">
            @foreach (\App\Enums\ExportFormat::cases() as $format)
                <flux:select.option :value="$format->value">{{ $format->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:checkbox
            wire:model="exportMetadata"
            :label="__('Include metadata')"
            :description="__('A header listing the reference, link, status and other fields.')"
            data-test="export-metadata"
        />

        {{-- Every control below applies only to what is nested under this item,
             so each one appears only when the subtree actually has it. --}}
        @if ($this->exportSubtreeDepth > 0)
            <flux:checkbox
                wire:model.live="exportDescendants"
                :label="__('Include descendants')"
                :description="__('Everything nested below this item, not just its direct children.')"
                data-test="export-descendants"
            />

            @if ($this->exportDescendants)
                <div class="flex flex-col gap-4 border-l border-zinc-200 pl-4 dark:border-zinc-700">
                    @if ($this->exportSubtreeDepth > 1)
                        <flux:select wire:model="exportDepth" :label="__('Levels')" data-test="export-depth">
                            <flux:select.option value="all">{{ __('All') }}</flux:select.option>
                            @for ($level = 1; $level <= $this->exportSubtreeDepth; $level++)
                                <flux:select.option :value="(string) $level">{{ $level }}</flux:select.option>
                            @endfor
                        </flux:select>
                    @endif

                    @if ($this->exportHasCanceled)
                        <flux:checkbox
                            wire:model="exportCanceled"
                            :label="__('Include canceled')"
                            data-test="export-canceled"
                        />
                    @endif

                    @if ($this->exportHasArchived)
                        <flux:checkbox
                            wire:model="exportArchived"
                            :label="__('Include archived')"
                            data-test="export-archived"
                        />
                    @endif

                    <flux:checkbox
                        wire:model.live="exportBundle"
                        :label="__('One file per item')"
                        :description="__('Delivered as a ZIP archive, instead of a single document.')"
                        data-test="export-bundle"
                    />

                    @if ($this->exportBundle)
                        <flux:select wire:model="exportLayout" :label="__('Files')" data-test="export-layout">
                            @foreach (\App\Enums\ExportFileLayout::cases() as $layout)
                                <flux:select.option :value="$layout->value">{{ $layout->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif

                    @if ($this->exportHasDrafts)
                        <flux:checkbox
                            wire:model="exportDrafts"
                            :label="__('Include drafts')"
                            data-test="export-drafts"
                        />
                    @endif
                </div>
            @endif
        @endif

        @if ($this->exportHasComments)
            <flux:checkbox
                wire:model="exportComments"
                :label="__('Include comments')"
                :description="__('The discussion under each exported item.')"
                data-test="export-comments"
            />
        @endif

        {{-- How images travel is only a question when the export has one. --}}
        @if ($this->exportHasImages)
            <flux:select wire:model.live="exportImages" :label="__('Images')" data-test="export-images">
                @foreach (\App\Enums\ExportImageMode::cases() as $mode)
                    <flux:select.option :value="$mode->value">{{ $mode->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($this->exportImages === \App\Enums\ExportImageMode::Inline->value)
                <flux:callout variant="warning" icon="exclamation-triangle" data-test="export-inline-warning">
                    <flux:callout.text>
                        {{ __('Embedded images make the export much larger — copying it can put megabytes on your clipboard.') }}
                    </flux:callout.text>
                </flux:callout>
            @endif
        @endif

        {{-- A filename choice, so it belongs with Download and says nothing
             about a copy to the clipboard. --}}
        <flux:checkbox
            wire:model="exportDatePrefix"
            :label="__('Prefix the filename with the date')"
            :description="__('Download only, e.g. 2026-08-02_abc-42-export-functionality.md')"
            data-test="export-date-prefix"
        />

        <div class="flex flex-wrap justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            {{-- An archive has nowhere to go on the clipboard, so the offer is
                 withdrawn rather than left to fail. --}}
            @unless ($this->exportBundle && $this->exportDescendants)
                <flux:button
                    icon="document-duplicate"
                    wire:click="copyExport"
                    data-test="export-copy"
                >{{ __('Copy to clipboard') }}</flux:button>
            @endunless
            <flux:button
                variant="primary"
                icon="arrow-down-tray"
                wire:click="downloadExport"
                data-test="export-download"
            >{{ __('Download') }}</flux:button>
        </div>
    </div>
</flux:modal>
