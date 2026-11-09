<?php

namespace App\Livewire\Activity;

use App\Audit\Sinks\ActivityLogSink;
use App\Concerns\ResolvesSubjectUrl;
use App\Enums\Status;
use App\Models\Activity;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Models\Variable;
use App\Support\ActivityDescriber;
use App\Support\Impersonation;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The cross-project activity page: what happened while you were away, across
 * every project you can read. This is the overview counterpart to the
 * notifications inbox — nothing here is addressed at you, it is simply
 * everything that happened, newest first.
 *
 * Two views over the same filters: the event stream ("events") lists every
 * change; the task overview ("tasks") collapses it to one row per task, the
 * most recently changed first, showing its latest change (KAN-586).
 *
 * @property-read CursorPaginator<int, Activity> $activities
 * @property-read LengthAwarePaginator<int, Activity> $updatedTasks
 * @property-read list<string> $tagOptions
 */
#[Title('Activity')]
class GlobalActivityFeed extends Component
{
    use ResolvesSubjectUrl;
    use WithPagination;

    public const int PER_PAGE = 30;

    /**
     * The activity-type filter's options: every feed-worthy action, grouped into
     * the handful of kinds a reader actually asks for. Derived from — and kept
     * exhaustive against — {@see ActivityLogSink::FEED_ACTIONS}, so a new action
     * cannot quietly become unfilterable (ActivityTypeFilterTest enforces it).
     *
     * @var array<string, list<string>>
     */
    public const array ACTION_CATEGORIES = [
        'comments' => ['commented', 'comment_deleted'],
        'progress' => ['created', 'status_changed', 'parent_changed', 'archived', 'unarchived', 'canceled', 'reopened'],
        'assignments' => ['assignee_changed', 'waiting_on_changed'],
        'details' => ['priority_changed', 'type_changed', 'tags_changed', 'dependency_changed'],
        'attachments' => ['attachment_added', 'attachment_removed'],
        'tags' => ['tag_renamed', 'tag_recolored', 'tag_deleted', 'tag_merged'],
        'variables' => ['variable_created', 'variable_renamed', 'variable_value_changed', 'variable_deleted'],
    ];

    /**
     * Which view to render: 'events' (the stream) or 'tasks' (one row per task).
     */
    #[Url]
    public string $view = 'events';

    /**
     * Restrict to tasks currently in this status (a {@see Status} value), or ''.
     */
    #[Url]
    public string $status = '';

    /**
     * Restrict to tasks carrying a tag of this name (any project), or ''.
     */
    #[Url]
    public string $tag = '';

    /**
     * Restrict to tasks whose reference or title contains this text, or ''.
     */
    #[Url]
    public string $search = '';

    /**
     * The public id of the person whose activity to show, or '' for everyone.
     * The opaque id rather than the numeric one: the filter lives in a URL that
     * gets shared.
     */
    #[Url]
    public string $actor = '';

    /**
     * The short name of the project to restrict to, or '' for all of them.
     */
    #[Url]
    public string $project = '';

    /**
     * A key of {@see self::ACTION_CATEGORIES}, or 'all'.
     */
    #[Url]
    public string $category = 'all';

    /**
     * How far back to look: all, today, week or month.
     */
    #[Url]
    public string $range = 'all';

    /**
     * Who performed the activity: 'all', 'people' (by hand in the app) or
     * 'agents' (through an API token or an OAuth-connected agent).
     */
    #[Url]
    public string $source = 'all';

    /**
     * Whether the reader's own manual activity is included. Off by default: on
     * a feed you visit to see what others did, your own clicks are the loudest
     * thing on the page and the least informative. Changes an agent made on the
     * reader's behalf are not "own" in that sense and always show (KAN-591).
     */
    #[Url]
    public bool $mine = false;

    /**
     * When the reader last opened this page, as an ISO timestamp — the line
     * between "new since your last visit" and the rest.
     *
     * Read and held here on mount while the stored value is stamped forward, so
     * the render that is supposed to show what is new still knows what was new.
     * Held as a property (not re-read) so it survives paging and filtering.
     */
    public ?string $seenAt = null;

    public function mount(): void
    {
        $user = Auth::user();

        // Looking at somebody else's session must not consume their unread
        // activity: an administrator opening this page would otherwise clear the
        // reader's "new since your last visit" line and their sidebar badge, for
        // them, permanently. Read the mark instead of moving it (KAN-578).
        $this->seenAt = Impersonation::isImpersonating()
            ? $user->activities_seen_at?->toIso8601String()
            : $user->markActivitiesSeen()?->toIso8601String();
    }

    /**
     * A filter change invalidates the cursor: it points at a row that may not
     * be in the new result set at all.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['view', 'actor', 'project', 'category', 'range', 'source', 'mine', 'status', 'tag', 'search'], true)) {
            $this->resetPage();
            unset($this->activities, $this->updatedTasks, $this->days, $this->descriptions);
        }
    }

    /**
     * The people offered by the actor filter: everyone who shares a project with
     * the reader — the same set whose activity can appear in the feed.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function actors(): Collection
    {
        return User::query()
            ->whereHas('projects', fn (Builder $projects) => $projects->whereIn(
                'projects.id',
                Auth::user()->projectIdsWithPermission('view-activity-log'),
            ))
            ->orderBy('name')
            ->get();
    }

    /**
     * The projects offered by the project filter.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        return Auth::user()->projects()->orderBy('title')->get();
    }

    /**
     * The tag names offered by the tag filter: every tag in the reader's
     * projects, deduplicated by name — the same name in two projects is one
     * option, and filtering by it matches both.
     *
     * @return list<string>
     */
    #[Computed]
    public function tagOptions(): array
    {
        return array_values(Tag::query()
            ->whereIn('project_id', Auth::user()->projectIdsWithPermission('view-activity-log'))
            ->orderBy('name')
            ->get(['name'])
            ->map(static fn (Tag $tag): string => $tag->name)
            ->unique(static fn (string $name): string => mb_strtolower($name))
            ->all());
    }

    /**
     * The label for an activity-type category.
     */
    public function categoryLabel(string $category): string
    {
        return match ($category) {
            'comments' => __('Comments'),
            'progress' => __('Progress'),
            'assignments' => __('Assignments'),
            'details' => __('Details'),
            'attachments' => __('Attachments'),
            'tags' => __('Tags'),
            'variables' => __('Variables'),
            default => __('All activity'),
        };
    }

    /**
     * Whether any filter is narrowing the feed right now — drives the "clear"
     * button. The default "without my own" is not counted: it is the resting
     * state, not something the reader set.
     */
    #[Computed]
    public function isFiltered(): bool
    {
        return $this->actor !== ''
            || $this->project !== ''
            || $this->category !== 'all'
            || $this->range !== 'all'
            || $this->source !== 'all'
            || $this->status !== ''
            || $this->tag !== ''
            || $this->search !== '';
    }

    public function clearFilters(): void
    {
        $this->reset(['actor', 'project', 'category', 'range', 'source', 'status', 'tag', 'search']);

        $this->resetPage();
        unset($this->activities, $this->updatedTasks, $this->days, $this->descriptions);
    }

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
        return $this->filtered(Activity::query()->visibleTo(Auth::user()))
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
     * The task overview: one row per task, the most recently changed first,
     * each represented by its latest activity that passes the filters.
     *
     * The grouping happens in SQL (the newest matching activity id per task),
     * so a page costs the same few queries however long each task's history is.
     * Offset pagination is fine here: a task that changes while you page moves
     * to the top rather than duplicating a row the way a growing stream would.
     *
     * @return LengthAwarePaginator<int, Activity>
     */
    #[Computed]
    public function updatedTasks(): LengthAwarePaginator
    {
        $latestPerTask = $this->filtered(Activity::query()->visibleTo(Auth::user()))
            ->where('activities.subject_type', (new Task)->getMorphClass())
            // A deleted task has nothing left to open; it does not belong in an
            // overview of what to look at.
            ->whereHasMorph('subject', [Task::class])
            ->selectRaw('max(activities.id)')
            ->groupBy('activities.subject_id');

        return Activity::query()
            ->whereIn('id', $latestPerTask)
            ->with(['user', 'project'])
            ->with(['subject' => static fn (Relation $subject): Relation => $subject instanceof MorphTo
                ? $subject->morphWith([Task::class => ['project']])
                : $subject])
            ->latest('created_at')
            ->latest('id')
            ->paginate(self::PER_PAGE);
    }

    /**
     * Apply the current filters to the (already authorized) feed query.
     *
     * The filters only ever narrow what the visibility scope allows: an actor or
     * project the reader cannot see resolves to nothing rather than widening the
     * result, so a hand-edited URL gains nobody anything.
     *
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    protected function filtered(Builder $query): Builder
    {
        if (! $this->mine) {
            $query->withoutManualActivityBy(Auth::user());
        }

        if ($this->source === 'people' || $this->source === 'agents') {
            $query->viaAgent($this->source === 'agents');
        }

        $this->filterBySubjectTask($query);

        if ($this->actor !== '') {
            $query->whereHas('user', fn (Builder $user) => $user->where('public_id', $this->actor));
        }

        if ($this->project !== '') {
            $query->whereHas('project', fn (Builder $project) => $project->where('short_name', $this->project));
        }

        if (array_key_exists($this->category, self::ACTION_CATEGORIES)) {
            $query->whereIn('action', self::ACTION_CATEGORIES[$this->category]);
        }

        $since = match ($this->range) {
            'today' => Carbon::now()->startOfDay(),
            'week' => Carbon::now()->subWeek(),
            'month' => Carbon::now()->subMonth(),
            default => null,
        };

        if ($since !== null) {
            $query->where('created_at', '>=', $since);
        }

        return $query;
    }

    /**
     * The task-level filters (status, tag, text): they narrow the activity to
     * that recorded on tasks matching them, in either view.
     *
     * @param  Builder<Activity>  $query
     */
    protected function filterBySubjectTask(Builder $query): void
    {
        $status = Status::tryFrom($this->status);
        $tag = mb_strtolower(trim($this->tag));
        $search = mb_strtolower(trim($this->search));

        if ($status === null && $tag === '' && $search === '') {
            return;
        }

        $query->whereHasMorph('subject', [Task::class], static function (Builder $task) use ($status, $tag, $search): void {
            if ($status !== null) {
                $task->where('status', $status);
            }

            if ($tag !== '') {
                $task->whereHas('tags', static fn (Builder $tags): Builder => $tags->whereRaw('lower(tags.name) = ?', [$tag]));
            }

            if ($search !== '') {
                $task->where(static fn (Builder $match): Builder => $match
                    ->whereRaw('lower(tasks.title) like ?', ['%'.$search.'%'])
                    ->orWhereHas('project', static fn (Builder $project): Builder => $project
                        ->whereRaw("lower(projects.short_name || '-' || tasks.task_number) like ?", ['%'.$search.'%'])));
            }
        });
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
        $items = $this->view === 'tasks' ? $this->updatedTasks->items() : $this->activities->items();

        return collect($items)
            ->mapWithKeys(static fn (Activity $activity): array => [
                $activity->id => ActivityDescriber::describe($activity),
            ])
            ->all();
    }

    /**
     * The id of the first row on this page that the reader has seen before —
     * the divider goes above it. Null when the whole page is new (or all of it
     * is old), in which case no divider is drawn.
     */
    #[Computed]
    public function firstSeenId(): ?int
    {
        if ($this->seenAt === null) {
            return null;
        }

        $seenAt = Carbon::parse($this->seenAt);
        $items = collect($this->activities->items());

        // Nothing new on this page: the reader is deep in history, and a "new
        // since" line at the very top would be a lie.
        if ($items->isEmpty() || $items->first()->created_at?->lessThanOrEqualTo($seenAt)) {
            return null;
        }

        return $items->first(static fn (Activity $activity): bool => $activity->created_at?->lessThanOrEqualTo($seenAt) ?? false)?->id;
    }

    /**
     * Whether the activity arrived after the reader's previous visit — marks a
     * task-overview row as new.
     */
    public function isNew(Activity $activity): bool
    {
        return $this->seenAt !== null
            && ($activity->created_at?->greaterThan(Carbon::parse($this->seenAt)) ?? false);
    }

    /**
     * The task URL for a task-overview row.
     */
    public function taskUrl(Activity $activity): ?string
    {
        return $this->subjectUrl($activity->subject);
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
