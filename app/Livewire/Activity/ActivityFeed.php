<?php

namespace App\Livewire\Activity;

use App\Concerns\ResolvesMorphSubject;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ActivityFeed extends Component
{
    use ResolvesMorphSubject;

    public const string COLLAPSED_PREFERENCE_KEY = 'activities_collapsed';

    public bool $collapsed = true;

    public function mount(Project|Story|Task $subject): void
    {
        $this->initMorphSubject($subject);

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
        return $this->resolveMorphSubject();
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
