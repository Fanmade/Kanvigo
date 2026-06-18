<?php

namespace App\Livewire\Subscriptions;

use App\Concerns\ResolvesMorphSubject;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SubscriptionToggle extends Component
{
    use ResolvesMorphSubject;

    public function mount(Project|Story|Task $subscribable): void
    {
        $this->initMorphSubject($subscribable);
    }

    #[Computed]
    public function subscribable(): Project|Story|Task
    {
        return $this->resolveMorphSubject();
    }

    #[Computed]
    public function subscribed(): bool
    {
        return $this->subscribable()->isSubscribedBy(Auth::user());
    }

    public function toggle(): void
    {
        $subscribable = $this->subscribable();
        $this->authorize('view', $subscribable);

        if ($this->subscribed()) {
            $subscribable->unsubscribe(Auth::user());
        } else {
            $subscribable->subscribe(Auth::user());
        }

        unset($this->subscribed);
    }
}
