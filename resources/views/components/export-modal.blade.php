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
            <flux:heading size="lg">{{ __('Export as Markdown') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Take this item with you as a Markdown file.') }}</flux:text>
        </div>

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

        <div class="flex flex-wrap justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button
                icon="document-duplicate"
                wire:click="copyExport"
                data-test="export-copy"
            >{{ __('Copy to clipboard') }}</flux:button>
            <flux:button
                variant="primary"
                icon="arrow-down-tray"
                wire:click="downloadExport"
                data-test="export-download"
            >{{ __('Download') }}</flux:button>
        </div>
    </div>
</flux:modal>
