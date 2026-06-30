<?php

namespace App\Mcp\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\JsonSchema\Types\Type;

/**
 * Opt-in cursor paging for the MCP read tools whose collections are otherwise
 * unbounded. The contract never truncates silently: without a "limit" every item
 * is returned and the page signal reports has_more=false. With a "limit" the tool
 * returns at most that many items plus an opaque next_cursor when more remain, so
 * an agent can decide whether to walk the rest or fetch the whole context at once.
 */
trait PagesResults
{
    /**
     * The largest page an explicit "limit" may request.
     */
    protected int $maxPageLimit = 200;

    /**
     * The validation rules for the optional limit/cursor inputs.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function pagingRules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.$this->maxPageLimit],
            'cursor' => ['nullable', 'string'],
        ];
    }

    /**
     * Decode a pagination cursor into a zero-based offset. An absent cursor is the
     * first page (offset 0); a malformed one returns null so the caller can reject
     * it rather than silently restart from the top.
     */
    protected function decodePageCursor(?string $cursor): ?int
    {
        if ($cursor === null || $cursor === '') {
            return 0;
        }

        $decoded = base64_decode($cursor, true);

        if ($decoded === false || ! preg_match('/^offset:(\d+)$/', $decoded, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Split a page off a result set. The caller fetches one extra row beyond the
     * limit (via {@see pageBound()}); if that extra row is present, more remain and
     * it is trimmed off. Without a limit the whole set is the page and nothing more
     * remains — the uncapped default that never truncates.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, TModel>  $fetched
     * @return array{0: Collection<int, TModel>, 1: bool}
     */
    protected function sliceFetchedPage(Collection $fetched, ?int $limit): array
    {
        if ($limit === null || $fetched->count() <= $limit) {
            return [$fetched, false];
        }

        return [$fetched->take($limit)->values(), true];
    }

    /**
     * Encode a zero-based offset into an opaque cursor.
     */
    protected function encodePageCursor(int $offset): string
    {
        return base64_encode('offset:'.$offset);
    }

    /**
     * Build the page signal for a response: how many items it carries, whether
     * more remain, and the cursor to fetch them.
     *
     * @return array{returned: int, has_more: bool, next_cursor: string|null}
     */
    protected function pageMeta(int $offset, ?int $limit, int $returned, bool $hasMore): array
    {
        return [
            'returned' => $returned,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore ? $this->encodePageCursor($offset + ($limit ?? $returned)) : null,
        ];
    }

    /**
     * The optional limit/cursor input-schema fields, described in terms of the
     * paged noun (e.g. "tasks", "notes").
     *
     * @return array<string, Type>
     */
    protected function pagingSchema(JsonSchema $schema, string $noun): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Optional maximum number of '.$noun.' to return (1–'.$this->maxPageLimit.'). Omit to return them all in one response.'),

            'cursor' => $schema->string()
                ->description('Optional pagination cursor taken from a previous response\'s page.next_cursor, to fetch the next page of '.$noun.'. Only meaningful together with "limit".'),
        ];
    }

    /**
     * The output-schema object describing the page signal.
     */
    protected function pageSchema(JsonSchema $schema): Type
    {
        return $schema->object([
            'returned' => $schema->integer()->description('How many items this response carries.')->required(),
            'has_more' => $schema->boolean()->description('Whether more items remain beyond this page.')->required(),
            'next_cursor' => $schema->string()->description('Pass as "cursor" to fetch the next page; null when no more remain.'),
        ])->description('Pagination signal. Without a "limit" every item is returned and has_more is false.')->required();
    }
}
