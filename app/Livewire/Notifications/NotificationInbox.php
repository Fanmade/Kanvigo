<?php

namespace App\Livewire\Notifications;

use App\Livewire\Notifications\Concerns\DescribesNotifications;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The full notification history: everything received, filtered and managed in
 * bulk, as opposed to the last ten in the header panel.
 *
 * @property-read LengthAwarePaginator<int, Notification> $notifications
 * @property-read Collection<int, Project> $projects
 */
class NotificationInbox extends Component
{
    use DescribesNotifications;
    use WithPagination;

    public const int PER_PAGE = 20;

    /**
     * Read state: all, unread or read.
     */
    #[Url]
    public string $status = 'all';

    /**
     * The short name of the project to restrict to, or '' for all projects.
     */
    #[Url]
    public string $project = '';

    /**
     * An activity-type category from {@see self::ACTION_CATEGORIES}, or 'all'.
     */
    #[Url]
    public string $category = 'all';

    /**
     * How far back to look: all, today, week or month.
     */
    #[Url]
    public string $range = 'all';

    /**
     * The ids ticked for a bulk action.
     *
     * @var list<string>
     */
    public array $selected = [];

    /**
     * Any filter change invalidates both the current page and the selection —
     * rows the user can no longer see must not be acted on by a bulk button.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'project', 'category', 'range'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    /**
     * @return LengthAwarePaginator<int, Notification>
     */
    #[Computed]
    public function notifications(): LengthAwarePaginator
    {
        return $this->query()->paginate(self::PER_PAGE);
    }

    /**
     * The projects offered by the project filter: the ones the user belongs to.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        return Auth::user()->projects()->orderBy('title')->get();
    }

    #[Computed]
    public function unreadCount(): int
    {
        return Auth::user()->unreadNotificationCount();
    }

    public function open(string $id): void
    {
        $notification = $this->find($id);
        $notification?->markAsRead();

        $url = $notification?->data['url'] ?? null;

        $this->refreshList();

        if (is_string($url)) {
            $this->redirect($url, navigate: true);
        }
    }

    public function markRead(string $id): void
    {
        $this->find($id)?->markAsRead();

        $this->refreshList();
    }

    public function markUnread(string $id): void
    {
        $this->find($id)?->markAsUnread();

        $this->refreshList();
    }

    public function dismiss(string $id): void
    {
        $this->find($id)?->delete();

        $this->refreshList();
    }

    public function markSelectedRead(): void
    {
        $this->applyToSelected(static fn (Builder $query): int => $query->whereNull('read_at')->update(['read_at' => now()]));
    }

    public function markSelectedUnread(): void
    {
        $this->applyToSelected(static fn (Builder $query): int => $query->whereNotNull('read_at')->update(['read_at' => null]));
    }

    public function dismissSelected(): void
    {
        $this->applyToSelected(static fn (Builder $query): ?bool => $query->delete());
    }

    /**
     * Tick every row on the current page, or clear the selection if they are
     * already all ticked.
     */
    public function toggleSelectPage(): void
    {
        $ids = array_values(array_map(
            static fn (Notification $notification): string => $notification->id,
            $this->notifications->items(),
        ));

        $this->selected = array_diff($ids, $this->selected) === []
            ? []
            : $ids;
    }

    public function render(): mixed
    {
        return view('livewire.notifications.notification-inbox');
    }

    /**
     * The filtered history, newest first. Unlike the header panel this keeps a
     * plain chronological order: the inbox is browsed and filtered, not drained.
     *
     * @return Builder<Notification>
     */
    private function query(): Builder
    {
        $query = Auth::user()->notifications()->getQuery();

        if ($this->status === 'unread') {
            $query->whereNull('read_at');
        } elseif ($this->status === 'read') {
            $query->whereNotNull('read_at');
        }

        if ($this->project !== '') {
            // Notifications carry the subject's reference ("ABC-42" for a task
            // or doc, "ABC" for the project itself), so the project short name
            // filters the history without a payload change or a join.
            $shortName = $this->project;

            $query->where(static function (Builder $scoped) use ($shortName): void {
                $scoped->where('data->reference', $shortName)
                    ->orWhere('data->reference', 'like', $shortName.'-%');
            });
        }

        if (array_key_exists($this->category, self::ACTION_CATEGORIES)) {
            $query->whereIn('data->action', self::ACTION_CATEGORIES[$this->category]);
        }

        $since = match ($this->range) {
            'today' => now()->startOfDay(),
            'week' => now()->subWeek(),
            'month' => now()->subMonth(),
            default => null,
        };

        if ($since !== null) {
            $query->where('created_at', '>=', $since);
        }

        return $query;
    }

    /**
     * One of the user's own notifications, so a tampered id can never reach
     * someone else's.
     */
    private function find(string $id): ?Notification
    {
        return Auth::user()->notifications()->whereKey($id)->first();
    }

    /**
     * Run a bulk statement over the ticked rows, scoped to the user's own
     * notifications. Bulk statements fire no model events, so the cached unread
     * count is busted by hand.
     *
     * @param  callable(Builder<Notification>): mixed  $action
     */
    private function applyToSelected(callable $action): void
    {
        if ($this->selected === []) {
            return;
        }

        $user = Auth::user();

        $action($user->notifications()->getQuery()->whereKey($this->selected));

        User::forgetUnreadNotificationCount($user->getKey());

        $this->selected = [];
        $this->refreshList();
    }

    /**
     * Drop the memoized list and counts after a change.
     */
    private function refreshList(): void
    {
        unset($this->notifications, $this->unreadCount);
    }
}
