<?php

namespace App\Enums;

use App\Concerns\SyncsInlineReferences;

/**
 * Where a cross-reference between two items came from.
 *
 * The distinction is what lets the two writers coexist: {@see SyncsInlineReferences}
 * re-derives the `Inline` links from the source's rich text on every save (adding
 * and removing as the text changes), while `Manual` links — created deliberately
 * through the API or a link action — are left alone by that sync.
 */
enum ReferenceOrigin: string
{
    case Inline = 'inline';
    case Manual = 'manual';
}
