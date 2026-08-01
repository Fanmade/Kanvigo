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
    /**
     * @param  bool  $metadata  include the YAML front-matter block
     * @param  bool  $descendants  include the exported item's whole subtree
     * @param  int|null  $depth  how many levels of descendants to include, or null
     *                           for every level — "all" is stored as an absence
     *                           rather than a number, so a subtree that grows
     *                           deeper later still exports in full
     * @param  bool  $canceled  include canceled tasks, which are otherwise skipped
     *                          along with everything below them
     * @param  bool  $drafts  include draft docs found among the descendants; a
     *                        directly-exported draft always exports
     */
    public function __construct(
        public bool $metadata = true,
        public bool $descendants = false,
        public ?int $depth = null,
        public bool $canceled = false,
        public bool $drafts = false,
    ) {}

    /**
     * The options as recorded in the audit event's metadata.
     *
     * @return array<string, bool|string>
     */
    public function toArray(): array
    {
        return [
            'metadata' => $this->metadata,
            'descendants' => $this->descendants,
            'depth' => $this->depth === null ? 'all' : (string) $this->depth,
            'canceled' => $this->canceled,
            'drafts' => $this->drafts,
        ];
    }
}
