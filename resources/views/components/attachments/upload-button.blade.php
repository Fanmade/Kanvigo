@props(['property' => 'newFiles'])

<div x-data class="flex items-center gap-3">
    <input
        type="file"
        multiple
        wire:model="{{ $property }}"
        x-ref="fileInput"
        class="hidden"
    />

    <flux:button size="xs" variant="ghost" icon="paper-clip" x-on:click="$refs.fileInput.click()">
        {{ __('Attach files') }}
    </flux:button>

    <flux:text
        size="sm"
        class="text-zinc-400"
        wire:loading
        wire:target="updatedNewFiles, {{ $property }}"
    >
        {{ __('Uploading…') }}
    </flux:text>

    @error($property.'.*')
        <flux:text size="sm" class="text-red-500">{{ $message }}</flux:text>
    @enderror
</div>
