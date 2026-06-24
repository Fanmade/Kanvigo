@props(['property' => 'description', 'label' => null, 'toolbar' => null, 'placeholder' => null, 'mentionablesUrl' => null])

{{--
    A Flux rich-text editor that stores HTML. Pasting or dropping an image is
    intercepted in the capture phase (so Tiptap doesn't inline it as base64),
    uploaded as an inline attachment, then inserted at the cursor by the
    `richEditor` Alpine component. See resources/js/app.js.

    When `mentionablesUrl` is supplied, the editor offers @mention / #reference
    autocomplete, fetching the project's members and tasks from that endpoint the
    first time a `@` or `#` is typed.
--}}
<div
    x-data="richEditor({ uploadFailedMessage: @js(__('Upload failed. Please try again.')) })"
    x-on:paste.capture="handlePaste($event)"
    x-on:dragover.prevent
    x-on:drop.capture.prevent="handleDrop($event)"
    @if ($mentionablesUrl !== null) data-mentionables-url="{{ $mentionablesUrl }}" @endif
>
    <flux:editor
        wire:model="{{ $property }}"
        :label="$label"
        :toolbar="$toolbar"
        :placeholder="$placeholder"
        :description="__('Paste or drop an image to embed it in the text.')"
    />

    <div x-show="uploading" x-cloak class="mt-1 flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
        <flux:icon name="arrow-up-tray" variant="micro" />
        {{ __('Uploading image…') }}
    </div>
</div>
