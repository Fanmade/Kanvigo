<div class="app-content mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="doc-page">
    @php($shortName = $this->doc->project->short_name)

    {{-- Breadcrumb: the project, the doc's ancestors, then this doc. --}}
    <div class="flex items-center justify-between gap-2">
        <div class="flex min-w-0 flex-wrap items-center gap-2 text-sm">
            <a href="{{ route('project.show', $this->doc->project) }}" wire:navigate class="text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">
                {{ $shortName }}
            </a>
            <span class="text-zinc-300">/</span>
            <a href="{{ route('project.docs', $this->doc->project) }}" wire:navigate class="text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200" data-test="docs-index-link">
                {{ __('Docs') }}
            </a>
            @foreach ($this->ancestors as $ancestor)
                <span class="text-zinc-300">/</span>
                <a
                    href="{{ route('doc.show', ['short_name' => $shortName, 'doc_number' => $ancestor->doc_number]) }}"
                    wire:navigate
                    class="font-mono text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200"
                    data-test="doc-ancestor-{{ $ancestor->id }}"
                >
                    {{ $shortName }}-D{{ $ancestor->doc_number }}
                </a>
            @endforeach
            <span class="text-zinc-300">/</span>
            <span class="font-mono text-zinc-400">{{ $this->doc->reference }}</span>
        </div>
    </div>

    @if ($editing)
        <form wire:submit="save" class="flex flex-col gap-4">
            <flux:input wire:model="title" :label="__('Title')" data-test="doc-title-input" />
            <flux:error name="title" />

            <x-attachments.rich-editor
                property="body"
                :label="__('Body')"
                :mentionables-url="$this->mentionablesUrl"
            />
            <x-attachments.upload-button />

            <flux:select wire:model="parentId" :label="__('Nested under')" data-test="doc-parent-select">
                <flux:select.option value="">{{ __('Top-level doc') }}</flux:select.option>
                @foreach ($this->parentOptions as $id => $label)
                    <flux:select.option :value="$id">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="parentId" />

            <div class="flex gap-2">
                <flux:button type="submit" variant="primary" data-test="save-doc">{{ __('Save') }}</flux:button>
                <flux:button variant="ghost" wire:click="$set('editing', false)">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @else
        <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:gap-8">
            {{-- Main column --}}
            <div class="flex min-w-0 flex-1 flex-col gap-6">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                    <flux:heading size="xl" class="min-w-0">{{ $this->doc->title }}</flux:heading>

                    @if ($this->canUpdate)
                        <div class="flex shrink-0 items-center gap-2">
                            @if ($this->canDelete)
                                <flux:dropdown align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Actions')" data-test="doc-actions" />
                                    <flux:menu>
                                        <flux:menu.item
                                            icon="trash"
                                            variant="danger"
                                            wire:click="delete"
                                            wire:confirm="{{ __('Delete this doc?') }}"
                                            data-test="delete-doc"
                                        >
                                            {{ __('Delete doc') }}
                                        </flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                            <flux:button size="sm" icon="pencil-square" variant="ghost" wire:click="edit" data-test="edit-doc">{{ __('Edit') }}</flux:button>
                        </div>
                    @endif
                </div>

                <x-attachments.dropzone :enabled="$this->canUpdate">
                    <flux:card>
                        @if ($this->doc->body)
                            <x-expandable-description :content="$this->doc->body" :short-name="$shortName" />
                        @else
                            <flux:text class="italic text-zinc-400">{{ __('This doc is still empty.') }}</flux:text>
                        @endif
                    </flux:card>
                </x-attachments.dropzone>

                <x-attachments.list :attachments="$this->attachments" />

                {{-- The docs nested under this one. --}}
                @if ($this->childDocs->isNotEmpty() || $this->canCreate)
                    <div data-test="child-docs-section">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <flux:heading size="sm">{{ __('Nested docs') }}</flux:heading>

                            @if ($this->canCreate)
                                <flux:button size="sm" icon="plus" wire:click="startCreatingChild" data-test="new-nested-doc">
                                    {{ __('New nested doc') }}
                                </flux:button>
                            @endif
                        </div>

                        <x-list-card data-test="child-docs">
                            @forelse ($this->childDocs as $child)
                                <x-doc-row :doc="$child" :short-name="$shortName" />
                            @empty
                                <flux:text size="sm" class="px-4 py-3 text-zinc-400">{{ __('No nested docs yet.') }}</flux:text>
                            @endforelse
                        </x-list-card>
                    </div>
                @endif
            </div>

            {{-- Metadata rail --}}
            <aside class="w-full shrink-0 lg:w-72">
                <flux:card class="flex flex-col gap-4">
                    <x-rail-row :label="__('Visibility')">
                        @if ($this->canUpdate)
                            <flux:button
                                size="xs"
                                variant="subtle"
                                :icon="$this->doc->is_public ? 'eye' : 'eye-slash'"
                                wire:click="togglePublished"
                                data-test="toggle-doc-published"
                            >
                                {{ $this->doc->is_public ? __('Published') : __('Draft') }}
                            </flux:button>
                        @else
                            <flux:badge size="sm" color="zinc" data-test="doc-visibility">
                                {{ $this->doc->is_public ? __('Published') : __('Draft') }}
                            </flux:badge>
                        @endif
                    </x-rail-row>

                    <x-rail-row :label="__('Parent')">
                        @if ($this->ancestors->isNotEmpty())
                            @php($parent = $this->ancestors->last())
                            <a
                                href="{{ route('doc.show', ['short_name' => $shortName, 'doc_number' => $parent->doc_number]) }}"
                                wire:navigate
                                class="truncate text-sm hover:underline"
                                data-test="doc-parent-link"
                            >
                                {{ $parent->title }}
                            </a>
                        @else
                            <flux:text size="sm" class="text-zinc-400">{{ __('Top-level doc') }}</flux:text>
                        @endif
                    </x-rail-row>

                    <flux:separator variant="subtle" />

                    {{-- Cross-references: what this doc points at, and what points back. --}}
                    <div class="flex flex-col gap-2" data-test="doc-links">
                        <flux:heading size="sm">{{ __('Links') }}</flux:heading>

                        @forelse ($this->linkedItems as $item)
                            <x-reference-item :item="$item" />
                        @empty
                            <flux:text size="sm" class="text-zinc-400">{{ __('No linked items yet.') }}</flux:text>
                        @endforelse
                    </div>

                    <div class="flex flex-col gap-2" data-test="doc-backlinks">
                        <flux:heading size="sm">{{ __('Linked from') }}</flux:heading>

                        @forelse ($this->backlinks as $item)
                            <x-reference-item :item="$item" />
                        @empty
                            <flux:text size="sm" class="text-zinc-400">{{ __('Nothing links here yet.') }}</flux:text>
                        @endforelse
                    </div>

                    <flux:separator variant="subtle" />

                    <div class="flex flex-col gap-1.5 text-sm">
                        <div class="flex items-center justify-between gap-2">
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Created') }}</flux:text>
                            <flux:text size="sm">{{ $this->doc->created_at->format('M j, Y') }}</flux:text>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Updated') }}</flux:text>
                            <flux:text size="sm"><x-relative-time :date="$this->doc->updated_at" /></flux:text>
                        </div>
                    </div>
                </flux:card>
            </aside>
        </div>
    @endif

    @if ($this->canCreate)
        <flux:modal wire:model.self="creatingChild" class="md:w-96" data-test="create-nested-doc-modal">
            <form wire:submit="createChild" class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('New nested doc') }}</flux:heading>
                <flux:text>{{ __('The new doc is nested under :title as a draft.', ['title' => $this->doc->title]) }}</flux:text>

                <flux:input wire:model="childTitle" :label="__('Title')" data-test="nested-doc-title" />
                <flux:error name="childTitle" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="create-nested-doc">{{ __('Create doc') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
