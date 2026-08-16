<?php

namespace App\Livewire\Activity;

use App\Concerns\ResolvesSubjectUrl;
use App\Models\Activity;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Models\Variable;
use App\Support\ActivityDescriber;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The cross-project activity page: what happened while you were away, across
 * every project you can read. This is the overview counterpart to the
 * notifications inbox — nothing here is addressed at you, it is simply
 * everything that happened, newest first.
 *
 * @property-read CursorPaginator<int, Activity> $activities
 */
#[Title('Activity')]
class GlobalActivityFeed extends Component
{
    use ResolvesSubjectUrl;
    use WithPagination;

    public const int PER_PAGE = 30;

    /**
     * The page of activity the user may read, newest first.
     *
     * Cursor pagination rather than offset: the feed grows at the head, so an
     * offset page would shift entries under the reader between clicks — page 2
     * repeats rows page 1 already showed. A cursor is anchored to a row instead.
     *
     * @return CursorPaginator<int, Activity>
     */
    #[Computed]
    public function activities(): CursorPaginator
    {
        return Activity::query()
            ->visibleTo(Auth::user())
            ->with(['user', 'project'])
            // Each row names its subject, and a task/doc/variable row also names
            // the project it sits in — without loading those up front the list
            // costs a query per row.
            ->with(['subject' => static fn (Relation $subject): Relation => $subject instanceof MorphTo
                ? $subject->morphWith([
                    Task::class => ['project'],
                    Doc::class => ['project'],
                    Variable::class => ['project'],
                    Project::class => [],
                ])
                : $subject])
            ->latest('created_at')
            ->latest('id')
            ->cursorPaginate(self::PER_PAGE);
    }

    /**
     * The current page grouped by calendar day, in display order. Grouping in
     * the component keeps the view a plain nested loop.
     *
     * @return Collection<string, Collection<int, Activity>>
     */
    #[Computed]
    public function days(): Collection
    {
        return collect($this->activities->items())
            ->groupBy(static fn (Activity $activity): string => $activity->created_at?->toDateString() ?? '');
    }

    /**
     * The description line for each activity on this page, keyed by id — the
     * same wording the per-item feed uses.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function descriptions(): array
    {
        return collect($this->activities->items())
            ->mapWithKeys(static fn (Activity $activity): array => [
                $activity->id => ActivityDescriber::describe($activity),
            ])
            ->all();
    }

    /**
     * The heading for a day group: the recent days by name, older ones by date.
     */
    public function dayLabel(string $date): string
    {
        $day = Carbon::parse($date);

        return match (true) {
            $day->isToday() => __('Today'),
            $day->isYesterday() => __('Yesterday'),
            default => $day->isoFormat('LL'),
        };
    }

    /**
     * The label for an activity's subject: its reference and title where it has
     * one, the plain name otherwise.
     */
    public function subjectLabel(Activity $activity): string
    {
        $subject = $activity->subject;

        return match (true) {
            $subject instanceof Task, $subject instanceof Doc => $subject->reference.' · '.$subject->title,
            $subject instanceof Project => $subject->short_name.' · '.$subject->title,
            $subject instanceof Variable => '['.$subject->name.']',
            default => __('Deleted item'),
        };
    }

    /**
     * Where the row links to. A task entry deep-links to the log entry itself;
     * everything else opens its subject's page. Null when the subject is gone —
     * the row still describes what happened, it just has nowhere to point.
     */
    public function rowUrl(Activity $activity): ?string
    {
        $subject = $activity->subject;

        if ($subject instanceof Task) {
            return $activity->deepLinkUrl();
        }

        if ($subject instanceof Variable) {
            return route('project.variables', ['short_name' => $subject->project->short_name]);
        }

        return $this->subjectUrl($subject);
    }

    public function render(): mixed
    {
        return view('livewire.activity.global-activity-feed');
    }
}
