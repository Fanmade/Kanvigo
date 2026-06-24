<?php

namespace App\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Appearance settings')]
class Appearance extends Component
{
    /**
     * The per-user preference key controlling whether page content spans the full
     * screen width instead of the default centered, capped reading column.
     */
    public const string FULL_WIDTH_PREFERENCE_KEY = 'full_width';

    public string $locale = 'en';

    /**
     * Whether the layout uses the full screen width, mirroring the saved preference.
     */
    public bool $fullWidth = false;

    public function mount(): void
    {
        $this->locale = app()->getLocale();
        $this->fullWidth = (bool) Auth::user()?->preference(self::FULL_WIDTH_PREFERENCE_KEY, false);
    }

    public function updatedLocale(string $value): void
    {
        if (! in_array($value, ['en', 'de'], true)) {
            return;
        }

        session(['locale' => $value]);

        // Reload so the locale middleware re-applies the new language.
        $this->redirectRoute('appearance.edit', navigate: true);
    }

    public function updatedFullWidth(bool $value): void
    {
        Auth::user()?->setPreference(self::FULL_WIDTH_PREFERENCE_KEY, $value);

        // Reload (without SPA navigation) so the root layout re-renders with the
        // new width class on the <html> element and the change takes effect at once.
        $this->redirectRoute('appearance.edit');
    }
}
