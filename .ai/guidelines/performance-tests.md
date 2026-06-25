# Performance Tests Assert Behavior, Not Mechanism

Performance tests must assert an **observable outcome against a threshold that
does not scale with data** — never the internal mechanism by which the code
achieves it. A test that pins *how* a result is produced (the exact number of
recursive CTE queries, whether a computed property memoizes, which relation is
eager-loaded) breaks on harmless refactors and doesn't express the thing we
actually care about: the page stays bounded as data grows.

## Write size-invariance / query-budget tests

The canonical performance guard renders (or runs) the same code over a **small**
and a **large** dataset and asserts the query count stays flat. If a regression
introduces an N+1, the large case exceeds the small one and the test fails —
regardless of how eager loading is implemented.

```php
$queriesToRender = function (int $subtasks): int {
    $task = makeTaskWithChildren($subtasks);

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::actingAs($this->member)
        ->test(TaskView::class, ['short_name' => $task->project->short_name, 'task_number' => $task->task_number])
        ->html();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
};

// 20 subtasks must issue no more queries than 2 — the subtree loads in bulk.
expect($queriesToRender(20))->toBeLessThanOrEqual($queriesToRender(2));
```

`tests/Feature/Board/KanbanTest.php` ("eager-loads breadcrumb ancestors instead
of one recursive query per nested card") and `BoardCacheTest` (idle re-render
serves from cache with **0** task queries) are the patterns to follow: each
asserts a budget that holds as the data grows, not a query shape.

## Don't pin the mechanism

Avoid assertions like "issues exactly N recursive queries", counting `recursive`
in the SQL, or probing computed-property memoization. These couple the test to
today's implementation, produce false failures on refactors, and read as
maintenance cost without catching the regressions that hurt users.

## Don't test a micro-optimization that only removes a constant query

Removing a single redundant query (one that does **not** scale with data) is not
worth a dedicated performance test — a threshold test can't see it without
descending into mechanism. Let the existing correctness tests cover the change,
and reserve performance tests for N+1s and unbounded loads.

## Rule of thumb

If the assertion would still pass after a reasonable refactor that keeps the page
just as fast, it's a good performance test. If it only passes for the exact code
you wrote, it's testing mechanism — rewrite it as a budget that holds across
small and large data.
