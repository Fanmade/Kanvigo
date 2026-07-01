<?php

namespace App\Concerns;

use App\Actions\ChangeTaskStatus;
use App\Enums\CascadePreference;
use App\Enums\Status;
use App\Models\Task;
use App\Support\StatusCascadeResult;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;

/**
 * Shared "close the parent too?" prompt for the task page and the boards. When a
 * status change leaves a parent with no open subtasks under the "ask" preference,
 * {@see ChangeTaskStatus} reports it and the component holds a confirmation here.
 * The parent is closed only on confirm — never silently (the "always" preference
 * is handled inside the action, "never" suppresses the prompt entirely).
 */
trait PromptsParentClose
{
    /**
     * A close-out awaiting the "also close the parent?" confirmation.
     */
    public bool $confirmingParentClose = false;

    public int $parentCloseId = 0;

    public string $parentCloseReference = '';

    public string $parentCloseStatus = '';

    /**
     * When set, the modal choice is remembered as the user's parent-close
     * preference ("always" on confirm, "never" on decline) so future closes skip it.
     */
    public bool $rememberParentCloseChoice = false;

    /**
     * Hold a prompt to close the now-childless parent, when the action reports one.
     */
    protected function maybePromptParentClose(StatusCascadeResult $result, Status $appliedStatus): void
    {
        if (! $result->parentClosedOut || $result->parentId === null) {
            return;
        }

        $parent = Task::find($result->parentId);

        if ($parent === null || Auth::user()?->cannot('updateStatus', $parent)) {
            return;
        }

        $this->parentCloseId = $parent->getKey();
        $this->parentCloseReference = $parent->reference;
        $this->parentCloseStatus = $appliedStatus->value;
        $this->confirmingParentClose = true;
    }

    /**
     * Close the parent too, and chain the prompt up if that empties its own parent.
     */
    public function confirmParentClose(): void
    {
        $parentId = $this->parentCloseId;
        $status = Status::tryFrom($this->parentCloseStatus);
        $remember = $this->rememberParentCloseChoice;
        $this->resetParentClosePrompt();

        if ($parentId === 0 || $status === null) {
            return;
        }

        $parent = Task::find($parentId);

        if ($parent === null) {
            return;
        }

        $this->authorize('updateStatus', $parent);

        if ($remember) {
            Auth::user()?->setPreference(ChangeTaskStatus::PARENT_CLOSE_PREFERENCE_KEY, CascadePreference::Always->value);
        }

        $result = app(ChangeTaskStatus::class)->handle($parent, $status);

        $this->maybePromptParentClose($result, $status);

        Flux::toast(text: __('Parent task closed.'), variant: 'success');
    }

    /**
     * Leave the parent open, optionally remembering never to ask again.
     */
    public function declineParentClose(): void
    {
        $remember = $this->rememberParentCloseChoice;
        $this->resetParentClosePrompt();

        if ($remember) {
            Auth::user()?->setPreference(ChangeTaskStatus::PARENT_CLOSE_PREFERENCE_KEY, CascadePreference::Never->value);
        }
    }

    /**
     * Dismiss the prompt without deciding (and without remembering anything).
     */
    public function dismissParentClose(): void
    {
        $this->resetParentClosePrompt();
    }

    /**
     * Clear the held prompt state.
     */
    protected function resetParentClosePrompt(): void
    {
        $this->confirmingParentClose = false;
        $this->parentCloseId = 0;
        $this->parentCloseReference = '';
        $this->parentCloseStatus = '';
        $this->rememberParentCloseChoice = false;
    }
}
