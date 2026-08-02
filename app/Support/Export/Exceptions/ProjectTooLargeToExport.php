<?php

namespace App\Support\Export\Exceptions;

use App\Models\Project;
use RuntimeException;

/**
 * A whole-project export that would cover more items than a request should
 * build in one go.
 *
 * Refusing with a countable reason is the point: "2 431 items, the limit is
 * 2 000" tells an operator what to raise, where a timed-out download tells them
 * nothing.
 */
final class ProjectTooLargeToExport extends RuntimeException
{
    private function __construct(
        public readonly Project $project,
        public readonly int $count,
        public readonly int $limit,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function of(Project $project, int $count, int $limit): self
    {
        return new self(
            $project,
            $count,
            $limit,
            "Exporting {$project->short_name} would cover {$count} items, above the limit of {$limit}.",
        );
    }
}
