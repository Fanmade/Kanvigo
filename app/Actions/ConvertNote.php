<?php

namespace App\Actions;

use App\Models\Note;
use App\Models\Task;

/**
 * Links a note to the task it was converted into. The note is kept (so its
 * "Converted → …" badge can point back at the task); only the link is recorded.
 */
class ConvertNote
{
    public function handle(Note $note, Task $task): Note
    {
        // converted_task_id is not user-fillable; set it directly.
        $note->converted_task_id = $task->id;
        $note->save();

        return $note;
    }
}
