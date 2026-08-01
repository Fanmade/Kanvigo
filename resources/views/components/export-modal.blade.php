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
