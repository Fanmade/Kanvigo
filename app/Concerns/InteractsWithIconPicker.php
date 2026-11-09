<?php

namespace App\Concerns;

use App\Support\IconCatalog;
use Livewire\Attributes\Computed;

/**
 * Search state for the shared `<x-icon-picker>` used by every Livewire
 * component that lets a tag or task type be given an icon. The full catalog is
 * a few hundred icons, so the picker shows a capped, name-filtered slice by
 * default; "show all" lifts the cap for browsing when the right name is unknown.
 *
 * @property-read list<string> $iconOptions
 * @property-read int $iconMatchCount
 */
trait InteractsWithIconPicker
{
    /**
     * The text typed into the icon picker's search field.
     */
    public string $iconQuery = '';

    /**
     * Whether the picker shows every matching icon (labelled overview) instead
     * of the capped compact slice.
     */
    public bool $showAllIcons = false;

    /**
     * The icons offered for the current query: capped so the compact picker
     * stays light, uncapped in the "show all" overview.
     *
     * @return list<string>
     */
    #[Computed]
    public function iconOptions(): array
    {
        return IconCatalog::search($this->iconQuery, $this->showAllIcons ? null : 60);
    }

    /**
     * How many icons match the current query in total, regardless of the cap.
     */
    #[Computed]
    public function iconMatchCount(): int
    {
        return IconCatalog::countMatching($this->iconQuery);
    }

    /**
     * Switch between the compact picker and the full icon overview.
     */
    public function toggleAllIcons(): void
    {
        $this->showAllIcons = ! $this->showAllIcons;

        unset($this->iconOptions);
    }

    /**
     * Drop the typed query and collapse the overview, so reopening the picker
     * starts from the top of the catalog rather than someone else's search.
     */
    public function resetIconQuery(): void
    {
        $this->iconQuery = '';
        $this->showAllIcons = false;

        unset($this->iconOptions, $this->iconMatchCount);
    }
}
