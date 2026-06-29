<?php

namespace App\Concerns;

use App\Models\Tag;
use App\Models\Task;
use App\Queries\TagSuggestions;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

/**
 * Adds tag management to a Task view component: listing the item's
 * tags, attaching existing ones, and creating new tags (with a color) through
 * the create-tag modal. Tags are applied live, mirroring dependency management.
 */
trait ManagesTags
{
    // Create-tag modal state.
    public bool $showTagModal = false;

    public string $newTagName = '';

    public string $newTagColor = 'zinc';

    /**
     * The chosen icon, or null for a colour-only tag. Declared null (not a
     * non-null default) so clearing it survives Livewire's omit-null hydration.
     */
    public ?string $newTagIcon = null;

    /**
     * The task whose tags are being managed.
     */
    abstract protected function taggable(): Task;

    /**
     * Bust the host component's cached subject computed (e.g. `task`)
     * so the applied-tags list re-reads from the database after a change.
     */
    abstract protected function forgetTaggable(): void;

    /**
     * Whether the current user may add or remove this item's tags.
     */
    #[Computed]
    public function canManageTags(): bool
    {
        return Gate::allows('update', $this->taggable());
    }

    /**
     * The tags currently applied to the viewed item.
     *
     * @return Collection<int, Tag>
     */
    #[Computed]
    public function appliedTags(): Collection
    {
        return $this->taggable()->tags;
    }

    /**
     * Up to a dozen of the most-used tags not already applied to this item,
     * offered as suggestions in the tag input.
     *
     * @return BaseCollection<int, array{name: string, color: string}>
     */
    #[Computed]
    public function tagSuggestions(): BaseCollection
    {
        return app(TagSuggestions::class)->handle(
            projectId: $this->taggable()->project_id,
            excludeIds: $this->appliedTags()->pluck('id')->all(),
        );
    }

    /**
     * Attach a tag by name to the viewed item, creating it (with an
     * auto-assigned color) if it does not exist yet.
     */
    public function addTag(string $name): void
    {
        $item = $this->taggable();
        $this->authorize('tag', $item);

        $name = trim($name);

        if ($name === '') {
            return;
        }

        $tag = Tag::findOrCreateForProject($item->project_id, $name);

        if ($item->tags()->whereKey($tag->getKey())->exists()) {
            return;
        }

        $item->tags()->attach($tag);
        $item->recordTagChange([$tag->name], []);

        $this->refreshTags();
    }

    /**
     * Detach a tag from the viewed item.
     */
    public function removeTag(int $tagId): void
    {
        $item = $this->taggable();
        $this->authorize('tag', $item);

        $tag = $item->tags()->whereKey($tagId)->first();

        if ($tag === null) {
            return;
        }

        $item->tags()->detach($tagId);
        $item->recordTagChange([], [$tag->name]);

        $this->refreshTags();
    }

    /**
     * Open the create-tag modal, prefilled with the typed text and a color
     * derived from it.
     */
    public function openTagModal(string $name = ''): void
    {
        $this->authorize('tag', $this->taggable());

        $this->resetErrorBag(['newTagName', 'newTagColor', 'newTagIcon']);
        $this->newTagName = trim($name);
        $this->newTagColor = Tag::colorForName($this->newTagName !== '' ? $this->newTagName : 'tag');
        $this->newTagIcon = null;
        $this->showTagModal = true;
    }

    /**
     * Create a tag with the chosen name and color and attach it to the item.
     */
    public function createTag(): void
    {
        $item = $this->taggable();
        $this->authorize('tag', $item);

        $validated = $this->validate([
            'newTagName' => ['required', 'string', 'max:255'],
            'newTagColor' => Tag::colorRule(allowDefault: true),
            'newTagIcon' => Tag::iconRule(),
        ]);

        $tag = Tag::findOrCreateForProject(
            $item->project_id,
            $validated['newTagName'],
            $validated['newTagColor'],
            $this->newTagIcon,
        );

        // Honor the chosen color and icon even when the tag already existed
        // (possibly under a different casing), so the modal's choices stick.
        if ($tag->color !== $validated['newTagColor']) {
            $tag->update(['color' => $validated['newTagColor']]);
        }

        if ($tag->icon !== $this->newTagIcon) {
            $tag->update(['icon' => $this->newTagIcon]);
        }

        if (! $item->tags()->whereKey($tag->getKey())->exists()) {
            $item->tags()->attach($tag);
            $item->recordTagChange([$tag->name], []);
        }

        $this->reset('showTagModal', 'newTagName', 'newTagIcon');
        $this->newTagColor = 'zinc';

        $this->refreshTags();

        Flux::toast(variant: 'success', text: __('Tag created.'));
    }

    /**
     * Refresh the applied tags and suggestions after a change, and notify the
     * tag-input widget so it can update its suggestion list in place.
     */
    protected function refreshTags(): void
    {
        $this->forgetTaggable();
        unset($this->appliedTags, $this->tagSuggestions);

        $this->dispatch('tags-updated', suggestions: $this->tagSuggestions()->all());
    }
}
