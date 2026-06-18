<?php

namespace App\Livewire\Subscriptions;

use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class SubscriptionToggle extends Component
{
    #[Locked]
    public string $subscribableType;

    #[Locked]
    public int $subscribableId;

    public function mount(Project|Story|Task $subscribable): void
    {
        $this->subscribableType = $subscribable->getMorphClass();
        $this->subscribableId = $subscribable->getKey();

        $this->authorize('view', $subscribable);
    }

    #[Computed]
    public function subscribable(): Project|Story|Task
    {
        $class = Relation::getMorphedModel($this->subscribableType) ?? $this->subscribableType;

        $subscribable = match ($class) {
            Project::class => Project::findOrFail($this->subscribableId),
            Story::class => Story::findOrFail($this->subscribableId),
            Task::class => Task::findOrFail($this->subscribableId),
            default => abort(404),
        };

        $this->authorize('view', $subscribable);

        return $subscribable;
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
