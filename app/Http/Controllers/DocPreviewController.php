<?php

namespace App\Http\Controllers;

use App\Queries\DocPreview;
use App\Support\ReferenceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class DocPreviewController extends Controller
{
    /**
     * The compact preview of a doc, fetched by the #reference hovercard when a
     * reader hovers a doc reference link. 404s for an unknown reference and 403s
     * when the reader cannot see the doc — a draft, say — so the card degrades
     * gracefully while the link itself keeps working.
     */
    public function __invoke(string $short_name, int $doc_number, DocPreview $preview): JsonResponse
    {
        $doc = ReferenceResolver::doc($short_name.'-D'.$doc_number);

        abort_if($doc === null, 404);

        Gate::authorize('view', $doc);

        return response()->json($preview->handle($doc));
    }
}
