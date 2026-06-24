<?php

namespace App\Livewire\Activity;

use App\Concerns\ResolvesMorphSubject;
use App\Enums\CancelReason;
use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class ActivityFeed extends Component
{
    use ResolvesMorphSubject;

    public const string COLLAPSED_PREFERENCE_KEY = 'activities_collapsed';

    public bool $collapsed = true;

    public function mount(Project|Task $subject): void
    {
        $this->initMorphSubject($subject);

        $this->collapsed = (bool) Auth::user()->preference(self::COLLAPSED_PREFERENCE_KEY, true);
    }

    /**
     * Live-updates tick: pull in activity recorded by others (read-only feed, so
     * always safe to refresh).
     */
    #[On('live-refresh')]
    public function liveRefresh(): void
    {
        unset($this->activities, $this->activityCount, $this->descriptions);
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
    public function subject(): Project|Task
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

    /**
     * Human-readable description line for each activity, keyed by activity id.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function descriptions(): array
    {
        return $this->activities()
            ->mapWithKeys(fn (Activity $activity): array => [$activity->id => $this->describe($activity)])
            ->all();
    }

    /**
     * Build the description line for a single activity.
     */
    private function describe(Activity $activity): string
    {
        $newValues = (array) json_decode((string) $activity->new_value, true);
        $oldValues = (array) json_decode((string) $activity->old_value, true);

        return match ($activity->action) {
            'created' => __('created this'),
            'status_changed' => __('changed status from :old to :new', [
                'old' => $this->statusLabel($activity->old_value),
                'new' => $this->statusLabel($activity->new_value),
            ]),
            'priority_changed' => __('changed priority from :old to :new', [
                'old' => $this->priorityLabel($activity->old_value),
                'new' => $this->priorityLabel($activity->new_value),
            ]),
            'type_changed' => $this->typeDescription($activity->old_value, $activity->new_value),
            'assignee_changed' => $this->assigneeDescription($newValues, $oldValues),
            'dependency_changed' => $this->dependencyDescription($newValues, $oldValues),
            'tags_changed' => $this->tagDescription($newValues, $oldValues),
            'tag_renamed' => __('renamed the tag :old to :new', ['old' => (string) $activity->old_value, 'new' => (string) $activity->new_value]),
            'tag_recolored' => __('changed the color of the tag :name', ['name' => (string) ($newValues['name'] ?? '')]),
            'tag_deleted' => __('deleted the tag :name', ['name' => (string) $activity->old_value]),
            'parent_changed' => $this->parentDescription($activity->old_value, $activity->new_value),
            'canceled' => $this->cancellationDescription($newValues),
            'reopened' => __('reopened this'),
            'archived' => __('archived this'),
            'unarchived' => __('restored this from the archive'),
            'commented' => __('added a comment'),
            default => $activity->action,
        };
    }

    /**
     * Describe a re-parent move from the old and new parent references (either
     * may be null, meaning the top level).
     */
    private function parentDescription(?string $old, ?string $new): string
    {
        return match (true) {
            $new !== null && $old !== null => __('moved this from :old to :new', ['old' => $old, 'new' => $new]),
            $new !== null => __('moved this under :new', ['new' => $new]),
            default => __('moved this to the top level'),
        };
    }

    /**
     * Describe a task-type change from the old and new type names (either may be
     * null — set from untyped, or cleared to untyped).
     */
    private function typeDescription(?string $old, ?string $new): string
    {
        return match (true) {
            $new !== null && $old !== null => __('changed type from :old to :new', ['old' => $old, 'new' => $new]),
            $new !== null => __('set the type to :new', ['new' => $new]),
            default => __('cleared the type'),
        };
    }

    /**
     * Resolve a status value to its label, falling back to the raw value.
     */
    private function statusLabel(?string $value): string
    {
        return Status::tryFrom((string) $value)?->label() ?? (string) $value;
    }

    /**
     * Resolve a priority value to its label, falling back to the raw value.
     */
    private function priorityLabel(?string $value): string
    {
        return Priority::tryFrom((int) $value)?->label() ?? (string) $value;
    }

    /**
     * Describe an assignee change from the added and removed names.
     *
     * @param  array<int, string>  $added
     * @param  array<int, string>  $removed
     */
    private function assigneeDescription(array $added, array $removed): string
    {
        $conjunction = ' '.__('and').' ';
        $addedList = Arr::join($added, ', ', $conjunction);
        $removedList = Arr::join($removed, ', ', $conjunction);

        return match (true) {
            $added !== [] && $removed !== [] => __('assigned :added, unassigned :removed', ['added' => $addedList, 'removed' => $removedList]),
            $added !== [] => __('assigned :users', ['users' => $addedList]),
            $removed !== [] => __('unassigned :users', ['users' => $removedList]),
            default => __('updated the assignees'),
        };
    }

    /**
     * Describe a tag change from the added and removed tags.
     *
     * @param  array<int, string>  $added
     * @param  array<int, string>  $removed
     */
    private function tagDescription(array $added, array $removed): string
    {
        $conjunction = ' '.__('and').' ';
        $addedList = Arr::join($added, ', ', $conjunction);
        $removedList = Arr::join($removed, ', ', $conjunction);

        return match (true) {
            $added !== [] && $removed !== [] => __('added the tags :added, removed :removed', ['added' => $addedList, 'removed' => $removedList]),
            $added !== [] => __('added the tags :tags', ['tags' => $addedList]),
            $removed !== [] => __('removed the tags :tags', ['tags' => $removedList]),
            default => __('updated the tags'),
        };
    }

    /**
     * Describe a cancellation from its reason and optional message snapshot.
     *
     * @param  array<string, string|null>  $payload
     */
    private function cancellationDescription(array $payload): string
    {
        $reason = CancelReason::tryFrom((string) ($payload['reason'] ?? ''))?->label();
        $message = $payload['message'] ?? null;

        return match (true) {
            $reason !== null && $message => __('canceled this as :reason — :message', ['reason' => $reason, 'message' => $message]),
            $reason !== null => __('canceled this as :reason', ['reason' => $reason]),
            default => __('canceled this'),
        };
    }

    /**
     * Describe a dependency change from the added or removed link.
     *
     * @param  array<string, string>  $added
     * @param  array<string, string>  $removed
     */
    private function dependencyDescription(array $added, array $removed): string
    {
        return match (true) {
            ($added['direction'] ?? null) === 'blocked_by' => __('is now blocked by :ref', ['ref' => $added['reference'] ?? '']),
            ($added['direction'] ?? null) === 'blocks' => __('now blocks :ref', ['ref' => $added['reference'] ?? '']),
            ($removed['direction'] ?? null) === 'blocked_by' => __('is no longer blocked by :ref', ['ref' => $removed['reference'] ?? '']),
            ($removed['direction'] ?? null) === 'blocks' => __('no longer blocks :ref', ['ref' => $removed['reference'] ?? '']),
            default => __('updated the dependencies'),
        };
    }
}
