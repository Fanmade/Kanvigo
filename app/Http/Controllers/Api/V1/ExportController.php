<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AccessAudit;
use App\Enums\ExportAttachmentMode;
use App\Enums\ExportFileLayout;
use App\Enums\ExportFormat;
use App\Enums\ExportImageMode;
use App\Http\Controllers\Api\V1\Concerns\ResolvesApiReferences;
use App\Http\Controllers\Controller;
use App\Models\Doc;
use App\Models\Task;
use App\Support\Export\ExportBundle;
use App\Support\Export\ExportOptions;
use App\Support\Export\ExportRenderer;
use App\Support\Facades\Audit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Exporting a task or doc as a document over the REST API — the same renderer
 * the web dialog uses, for machines rather than people.
 *
 * It is a sub-resource (`/tasks/{reference}/export`) rather than a content
 * negotiation on the item endpoint. `Accept: text/markdown` reads well in a
 * specification, but it makes one URL answer with two unrelated bodies, which
 * caches and proxies handle badly — and a stray browser `Accept` header must
 * never turn the JSON API into something else.
 *
 * Options are query parameters and default to what the dialog defaults to, never
 * to the caller's remembered preferences: an API response that depends on what
 * its owner last clicked in a browser is not reproducible.
 */
class ExportController extends Controller
{
    use ResolvesApiReferences;

    /**
     * Export a task as a document.
     *
     * Renders the task — and optionally its subtree, comments, images and
     * attachments — as Markdown or a standalone HTML page. Options that need
     * files to travel (a bundle, or images/attachments as files) return a ZIP
     * archive instead of a document.
     */
    public function task(Request $request, string $reference): Response
    {
        return $this->export($request, $this->resolveTaskOr404($reference));
    }

    /**
     * Export a doc as a document. A draft is invisible to anyone who may not
     * edit the project's docs, so it 404s for them like any other reference.
     */
    public function doc(Request $request, string $reference): Response
    {
        return $this->export($request, $this->resolveDocOr404($reference));
    }

    /**
     * Render the item and hand it back with the content type it actually is.
     */
    private function export(Request $request, Task|Doc $item): Response
    {
        abort_if(Auth::user()->cannot('export-content', $item->project), 403);

        $options = $this->options($request);

        Audit::record(AccessAudit::contentExported($item, $options->format->value, $options->toArray()));

        if ($options->needsArchive()) {
            $bundle = app(ExportBundle::class);

            return $this->respond(
                $bundle->zip($item, $options),
                'application/zip',
                $bundle->filename($item, $options),
            );
        }

        $renderer = app(ExportRenderer::class);

        return $this->respond(
            $renderer->render($item, $options),
            $options->format->mimeType(),
            $renderer->filename($item, $options),
        );
    }

    /**
     * The export the query parameters describe. Every default matches the
     * dialog's, so an unadorned request returns the obvious thing: the item
     * itself, with its metadata, and images left as links to this instance.
     */
    private function options(Request $request): ExportOptions
    {
        $validated = $request->validate([
            'format' => ['nullable', Rule::enum(ExportFormat::class)],
            'metadata' => ['nullable', 'boolean'],
            'descendants' => ['nullable', 'boolean'],
            'depth' => ['nullable', 'integer', 'min:1', 'max:20'],
            'canceled' => ['nullable', 'boolean'],
            'archived' => ['nullable', 'boolean'],
            'drafts' => ['nullable', 'boolean'],
            'comments' => ['nullable', 'boolean'],
            'bundle' => ['nullable', 'boolean'],
            'layout' => ['nullable', Rule::enum(ExportFileLayout::class)],
            'images' => ['nullable', Rule::enum(ExportImageMode::class)],
            'attachments' => ['nullable', Rule::enum(ExportAttachmentMode::class)],
        ]);

        $descendants = $request->boolean('descendants');

        return new ExportOptions(
            metadata: $request->boolean('metadata', true),
            descendants: $descendants,
            depth: isset($validated['depth']) ? (int) $validated['depth'] : null,
            canceled: $request->boolean('canceled'),
            archived: $request->boolean('archived'),
            drafts: $request->boolean('drafts'),
            comments: $request->boolean('comments'),
            // A bundle is one file per item, so it only means anything with a
            // subtree to split up.
            bundle: $request->boolean('bundle') && $descendants,
            layout: ExportFileLayout::tryFrom((string) ($validated['layout'] ?? '')) ?? ExportFileLayout::Flat,
            format: ExportFormat::tryFrom((string) ($validated['format'] ?? '')) ?? ExportFormat::Markdown,
            attachments: ExportAttachmentMode::tryFrom((string) ($validated['attachments'] ?? '')) ?? ExportAttachmentMode::None,
            images: ExportImageMode::tryFrom((string) ($validated['images'] ?? '')) ?? ExportImageMode::Embed,
        );
    }

    /**
     * The body with its type and a filename, so `curl -OJ` saves something
     * sensibly named without the caller having to construct it.
     */
    private function respond(string $body, string $contentType, string $filename): Response
    {
        return response($body, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
