<?php

namespace App\Support\Export;

/**
 * What an export was asked to include.
 *
 * The one value object the export feature shares: the modal binds to it, the
 * audit event records it, and a later per-user preference will persist it. The
 * renderers stay concrete (see docs/adr/0002-export-has-no-format-abstraction.md),
 * so this — not a format interface — is the seam that grows as options arrive.
 */
final readonly class ExportOptions
{
    public function __construct(public bool $metadata = true) {}

    /**
     * The options as recorded in the audit event's metadata.
     *
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return ['metadata' => $this->metadata];
    }
}
