<?php

namespace App\Support;

use App\Models\Doc;
use App\Models\Task;
use Laravel\Mcp\Response;

/**
 * Outcome of resolving the two ends of a cross-reference link: either the
 * resolved pair (the item being changed + the item it links to) or an error
 * {@see Response} to return to the caller. Both ends may be a task or a doc,
 * which is what sets this apart from {@see DependencyPairResolution}.
 */
final readonly class ReferencePairResolution
{
    private function __construct(
        public Task|Doc|null $item,
        public Task|Doc|null $related,
        public ?Response $error,
    ) {}

    public static function success(Task|Doc $item, Task|Doc $related): self
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
     * @return array{0: Task|Doc, 1: Task|Doc}
     */
    public function pair(): array
    {
        assert($this->item !== null && $this->related !== null);

        return [$this->item, $this->related];
    }
}
