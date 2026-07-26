<div class="app-content mx-auto flex w-full max-w-4xl flex-col gap-6" data-test="docs-page">
    <x-project-settings-header :project="$this->project" :title="__('Docs')">
        @if ($this->canCreate)
            <flux:button size="sm" icon="plus" wire:click="startCreating" data-test="new-doc">{{ __('New doc') }}</flux:button>
        @endif
    </x-project-settings-header>

    <flux:input
        wire:model.live.debounce.300ms="search"
        icon="magnifying-glass"
        :placeholder="__('Search docs…')"
        class="sm:max-w-xs"
        clearable
        data-test="doc-search"
    />

    @if ($this->docs->isEmpty())
        <x-empty-state
            icon="document-text"
            :heading="$this->isSearching ? __('No docs match your search.') : __('No docs yet.')"
            test="docs-empty"
        >
            @unless ($this->isSearching)
                <flux:text size="sm" class="text-zinc-400">
                    {{ __('Docs hold the specs, decisions and background this project keeps coming back to.') }}
                </flux:text>
            @endunless
        </x-empty-state>
    @else
        <x-list-card data-test="docs-list">
            @if ($this->isSearching)
                {{-- A search shows its matches flat: a filtered tree would hide a
                     matching doc under a parent that doesn't match. --}}
                @foreach ($this->docs as $doc)
                    <x-doc-row :doc="$doc" :short-name="$this->project->short_name" />
                @endforeach
            @else
                @foreach ($this->docsByParent->get('root', collect()) as $doc)
                    <x-doc-tree-item
                        :doc="$doc"
                        :children-by-parent="$this->docsByParent"
                        :short-name="$this->project->short_name"
                    />
                @endforeach
            @endif
        </x-list-card>
    @endif

    @if ($this->canCreate)
        <flux:modal wire:model.self="creating" class="md:w-96" data-test="create-doc-modal">
            <form wire:submit="create" class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('New doc') }}</flux:heading>

                <flux:input wire:model="newTitle" :label="__('Title')" data-test="new-doc-title" />
                <flux:error name="newTitle" />

                <flux:select wire:model="newParentId" :label="__('Nested under')" data-test="new-doc-parent">
                    <flux:select.option value="">{{ __('Top-level doc') }}</flux:select.option>
                    @foreach ($this->docs as $doc)
                        <flux:select.option :value="$doc->id">{{ $this->project->short_name }}-D{{ $doc->doc_number }} · {{ $doc->title }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="newParentId" />

                <flux:text size="sm" class="text-zinc-400">
                    {{ __('The doc starts as a draft only editors can see. Publish it once it is ready.') }}
                </flux:text>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="create-doc">{{ __('Create doc') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
