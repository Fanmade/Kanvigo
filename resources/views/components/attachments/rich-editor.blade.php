@props(['property' => 'description', 'label' => null, 'toolbar' => null, 'preset' => null, 'placeholder' => null, 'mentionablesUrl' => null])

{{--
    A Flux rich-text editor that stores HTML. Pasting or dropping an image is
    intercepted in the capture phase (so Tiptap doesn't inline it as base64),
    uploaded as an inline attachment, then inserted at the cursor by the
    `richEditor` Alpine component. See resources/js/app.js.

    When `mentionablesUrl` is supplied, the editor offers @mention / #reference
    autocomplete, fetching the project's members and tasks from that endpoint the
    first time a `@` or `#` is typed.

    The toolbar is set either explicitly (`toolbar="…"`) or by a named `preset`,
    which keeps the shared button sets in one place instead of hand-copied strings:
      - "compact": the comment/note set (formatting, lists, link).
      - "comment": the fuller comment-edit set (adds blockquote, align, undo/redo).
--}}
@php
    $toolbarPresets = [
        'compact' => 'bold italic strike | bullet ordered | link',
        'comment' => 'bold italic strike | bullet ordered blockquote | link | align ~ undo redo',
    ];

    $resolvedToolbar = $toolbar ?? ($preset !== null ? ($toolbarPresets[$preset] ?? null) : null);
@endphp
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
        :toolbar="$resolvedToolbar"
        :placeholder="$placeholder"
        :description="__('Paste or drop an image to embed it in the text.')"
    />

    <div x-show="uploading" x-cloak class="mt-1 flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
        <flux:icon name="arrow-up-tray" variant="micro" />
        {{ __('Uploading image…') }}
    </div>
</div>
