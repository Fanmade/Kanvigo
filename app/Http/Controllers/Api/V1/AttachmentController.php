<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\StoreAttachment;
use App\Http\Controllers\Api\V1\Concerns\ResolvesApiReferences;
use App\Http\Controllers\Concerns\ResolvesImageTransforms;
use App\Http\Controllers\Concerns\ServesScopedAttachments;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Support\Facades\Audit;
use App\Support\Images\ImageTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    use ResolvesApiReferences;
    use ResolvesImageTransforms;
    use ServesScopedAttachments;

    /**
     * List a project's downloadable attachments (inline description images excluded).
     */
    public function indexForProject(string $short_name): AnonymousResourceCollection
    {
        return $this->list($this->resolveProjectOr404($short_name));
    }

    /**
     * List a task's downloadable attachments.
     */
    public function indexForTask(string $reference): AnonymousResourceCollection
    {
        return $this->list($this->resolveTaskOr404($reference));
    }

    /**
     * Stream an attachment's file content as a download.
     *
     * Byte-exact by default. Passing any of width/height/format/quality opts into
     * a re-encoded rendition instead.
     */
    public function download(Request $request, int $attachment): StreamedResponse|Response
    {
        $model = Attachment::find($attachment);

        abort_if($model === null || Auth::user()->cannot('view', $model), 404);

        $spec = $this->imageTransformSpec($request);

        if ($spec === null) {
            return $this->downloadAttachment($model);
        }

        return $this->transformedAttachmentResponse($model, $spec);
    }

    /**
     * An attachment's full metadata record.
     *
     * Separate from the listing payload because this is where data too heavy to
     * repeat on every attachment of every task belongs — starting with the
     * automatically generated descriptions planned in KAN-544.
     */
    public function metadata(int $attachment, ImageTransformer $transformer): JsonResponse
    {
        $model = Attachment::find($attachment);

        abort_if($model === null || Auth::user()->cannot('view', $model), 404);

        return AttachmentResource::make($model)
            ->additional(['data' => [
                // Whether an available driver can decode and re-encode this
                // file — a MIME-type check plus encoder support, not a stored
                // `width`: width is null for every image uploaded before the
                // dimensions columns existed, and (once populated) is also set
                // for non-image formats a driver can merely rasterize (PDF), so
                // its presence or absence is not reliable evidence either way.
                'transformable' => str_starts_with((string) $model->mime_type, 'image/') && $transformer->supportsFormat('webp'),
            ]])
            ->response();
    }

    /**
     * Upload a file to a project.
     */
    public function storeOnProject(Request $request, string $short_name): JsonResponse
    {
        return $this->upload($request, $this->resolveProjectOr404($short_name));
    }

    /**
     * Upload a file to a task.
     */
    public function storeOnTask(Request $request, string $reference): JsonResponse
    {
        return $this->upload($request, $this->resolveTaskOr404($reference));
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
            Audit::record($attachable->contentAuditEvent('attachment_removed', 'attachments', $name));
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

        Audit::record($attachable->contentAuditEvent('attachment_added', 'attachments'));

        return AttachmentResource::make($attachment)->response()->setStatusCode(201);
    }
}
