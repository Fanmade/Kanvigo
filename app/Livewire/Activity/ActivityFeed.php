<?php

namespace App\Livewire\Activity;

use App\Models\Activity;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ActivityFeed extends Component
{
    public const string COLLAPSED_PREFERENCE_KEY = 'activities_collapsed';

    #[Locked]
    public string $subjectType;

    #[Locked]
    public int $subjectId;

    public bool $collapsed = true;

    public function mount(Project|Story|Task $subject): void
    {
        $this->subjectType = $subject->getMorphClass();
        $this->subjectId = $subject->getKey();

        $this->authorize('view', $subject);

        $this->collapsed = (bool) Auth::user()->preference(self::COLLAPSED_PREFERENCE_KEY, true);
    }

    /**
     * Toggle the activity feed and persist the state as a user preference.
     */
    public function toggleCollapsed(): void
    {
        $this->collapsed = ! $this->collapsed;

        Auth::user()->setPreference(self::COLLAPSED_PREFERENCE_KEY, $this->collapsed);
    }

    /**
     * Resolve the model the activities belong to.
     */
    #[Computed]
    public function subject(): Project|Story|Task
    {
        $class = Relation::getMorphedModel($this->subjectType) ?? $this->subjectType;

        $subject = match ($class) {
            Project::class => Project::findOrFail($this->subjectId),
            Story::class => Story::findOrFail($this->subjectId),
            Task::class => Task::findOrFail($this->subjectId),
            default => abort(404),
        };

        $this->authorize('view', $subject);

        return $subject;
    }

    /**
     * The subject's recorded activities (newest first) with their author.
     *
     * @return Collection<int, Activity>
     */
    #[Computed]
    public function activities(): Collection
    {
        return $this->subject()->activities()->with('user')->get();
    }

    /**
     * Count of recorded activities, used for the collapsed-state badge.
     */
    #[Computed]
    public function activityCount(): int
    {
        return $this->subject()->activities()->count();
    }
}
