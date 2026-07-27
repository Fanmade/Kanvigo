<?php

namespace App\Notifications\Concerns;

use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;

/**
 * Resolves a notification's subject to the web URL the notifications menu links
 * to: the task page for a Task, the doc page for a Doc, the project page for a
 * Project, or null for anything else. Shared so {@see ItemActivity} and
 * {@see UserMentioned} link the same way.
 */
trait ResolvesSubjectUrl
{
    protected function subjectUrl(mixed $subject): ?string
    {
        return match (true) {
            $subject instanceof Task => route('task.show', [
                'short_name' => $subject->project->short_name,
                'task_number' => $subject->task_number,
            ]),
            $subject instanceof Doc => route('doc.show', [
                'short_name' => $subject->project->short_name,
                'doc_number' => $subject->doc_number,
            ]),
            $subject instanceof Project => route('project.show', ['short_name' => $subject->short_name]),
            default => null,
        };
    }
}
