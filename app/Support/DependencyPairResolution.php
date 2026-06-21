<?php

namespace App\Support;

use App\Models\Task;
use Laravel\Mcp\Response;

/**
 * Outcome of resolving the two ends of a dependency link: either the resolved
 * pair (item + related) or an error {@see Response} to return to the caller.
 */
final readonly class DependencyPairResolution
{
    private function __construct(
        public ?Task $item,
        public ?Task $related,
        public ?Response $error,
    ) {}

    public static function success(Task $item, Task $related): self
    {
        return new self($item, $related, null);
    }

    public static function failure(Response $error): self
    {
        return new self(null, null, $error);
    }

    public function failed(): bool
    {
        return $this->error instanceof Response;
    }

    /**
     * The error to return. Only valid when {@see failed()} is true.
     */
    public function error(): Response
    {
        assert($this->error instanceof Response);

        return $this->error;
    }

    /**
     * The resolved pair as [item, related]. Only valid when the resolution
     * succeeded.
     *
     * @return array{0: Task, 1: Task}
     */
    public function pair(): array
    {
        assert($this->item !== null && $this->related !== null);

        return [$this->item, $this->related];
    }
}
