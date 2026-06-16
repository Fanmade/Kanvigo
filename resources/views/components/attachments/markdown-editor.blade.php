@props(['property' => 'description', 'label' => null, 'rows' => 8])

<div
    x-data="{
        uploading: false,
        imageFiles(list) {
            return Array.from(list || []).filter(file => file.type.startsWith('image/'));
        },
        async embed(file) {
            const textarea = this.$el.querySelector('textarea');
            const cursor = textarea ? textarea.selectionStart : 0;
            this.uploading = true;
            await new Promise((resolve) => {
                this.$wire.upload(
                    'inlineImage',
                    file,
                    () => this.$wire.addInlineImage(cursor).then(resolve),
                    () => resolve(),
                );
            });
            this.uploading = false;
        },
        async handle(list) {
            for (const file of this.imageFiles(list)) {
                await this.embed(file);
            }
        },
    }"
    x-on:paste="if (imageFiles($event.clipboardData?.files).length) { $event.preventDefault(); handle($event.clipboardData.files); }"
    x-on:dragover.prevent
    x-on:drop.prevent="handle($event.dataTransfer?.files)"
>
    <flux:textarea
        wire:model="{{ $property }}"
        :label="$label"
        :rows="$rows"
        :description="__('Paste or drop an image to embed it in the text.')"
    />

    <div x-show="uploading" x-cloak class="mt-1 flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
        <flux:icon name="arrow-up-tray" variant="micro" />
        {{ __('Uploading image…') }}
    </div>
</div>
