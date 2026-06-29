<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\StoreAttachment;
use App\Http\Controllers\Concerns\ServesScopedAttachments;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Support\ReferenceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    use ServesScopedAttachments;

    /**
     * List a project's downloadable attachments (inline description images excluded).
     */
    public function indexForProject(string $short_name): AnonymousResourceCollection
    {
        $project = ReferenceResolver::project($short_name);

        abort_if($project === null || Auth::user()->cannot('view', $project), 404);

        return $this->list($project);
    }

    /**
     * List a task's downloadable attachments.
     */
    public function indexForTask(string $reference): AnonymousResourceCollection
    {
        $task = ReferenceResolver::task($reference);

        abort_if($task === null || Auth::user()->cannot('view', $task), 404);

        return $this->list($task);
    }

    /**
     * Stream an attachment's file content as a download.
     */
    public function download(int $attachment): StreamedResponse
    {
        $model = Attachment::find($attachment);

        abort_if($model === null || Auth::user()->cannot('view', $model), 404);

        return $this->downloadAttachment($model);
    }

    /**
     * Upload a file to a project.
     */
    public function storeOnProject(Request $request, string $short_name): JsonResponse
    {
        $project = ReferenceResolver::project($short_name);

        abort_if($project === null || Auth::user()->cannot('view', $project), 404);

        return $this->upload($request, $project);
    }

    /**
     * Upload a file to a task.
     */
    public function storeOnTask(Request $request, string $reference): JsonResponse
    {
        $task = ReferenceResolver::task($reference);

        abort_if($task === null || Auth::user()->cannot('view', $task), 404);

        return $this->upload($request, $task);
    }

    /**
     * Delete an attachment.
     */
    public function destroy(int $attachment): JsonResponse
    {
        $model = Attachment::find($attachment);

        abort_if($model === null || Auth::user()->cannot('view', $model->attachable), 404);
        abort_if(Auth::user()->cannot('delete', $model), 403);

        $attachable = $model->attachable;
        $name = $model->name;
        $model->delete();

        if ($attachable instanceof Project || $attachable instanceof Task) {
            $attachable->recordActivity('attachment_removed', 'attachments', $name);
        }

        return response()->json(status: 204);
    }

    /**
     * The downloadable (non-inline) attachments of a commentable, paginated —
     * attachments grow unbounded per project/task, so the listing is capped like
     * the other collection endpoints (tasks, notes, comments).
     */
    protected function list(Project|Task $attachable): AnonymousResourceCollection
    {
        return AttachmentResource::collection(
            $attachable->attachments()->where('is_inline', false)->latest()->paginate(),
        );
    }

    /**
     * Authorize, validate and store an uploaded file against the given owner.
     */
    protected function upload(Request $request, Project|Task $attachable): JsonResponse
    {
        abort_if(Auth::user()->cannot('create', [Attachment::class, $attachable]), 403);

        $maxSize = (int) config('attachments.max_size');

        $request->validate([
            'file' => ['required', 'file', "max:{$maxSize}"],
        ]);

        $attachment = app(StoreAttachment::class)->handle($request->file('file'), $attachable);

        $attachable->recordActivity('attachment_added', 'attachments');

        return AttachmentResource::make($attachment)->response()->setStatusCode(201);
    }
}
