<?php

namespace App\Concerns;

use App\Support\IconCatalog;
use Livewire\Attributes\Computed;

/**
 * Search state for the shared `<x-icon-picker>` used by every Livewire
 * component that lets a tag or task type be given an icon. The full catalog is
 * a few hundred icons, so the picker shows a capped, name-filtered slice and
 * the using component only has to carry the query.
 *
 * @property-read list<string> $iconOptions
 */
trait InteractsWithIconPicker
{
    /**
     * The text typed into the icon picker's search field.
     */
    public string $iconQuery = '';

    /**
     * The icons offered for the current query, capped so the picker stays light.
     *
     * @return list<string>
     */
    #[Computed]
    public function iconOptions(): array
    {
        return IconCatalog::search($this->iconQuery);
    }

    /**
     * Drop the typed query, so reopening the picker starts from the top of the
     * catalog rather than someone else's search.
     */
    public function resetIconQuery(): void
    {
        $this->iconQuery = '';

        unset($this->iconOptions);
    }
}
