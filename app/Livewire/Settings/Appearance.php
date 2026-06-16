<?php

namespace App\Livewire\Settings;

use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Appearance settings')]
class Appearance extends Component
{
    public string $locale = 'en';

    public function mount(): void
    {
        $this->locale = app()->getLocale();
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
}
