<?php

namespace App\Support;

use App\Contracts\Referenceable;
use App\Models\Doc;
use App\Models\Task;
use Dom\HTMLDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Extracts the #references written into stored rich-text (HTML) and resolves
 * them to the items they point at.
 *
 * A reference is stored as an atomic inline link carrying the target's id and
 * kind, e.g.
 * {@code <a class="reference" data-type="reference" data-item-type="doc" data-id="7" href="/ABC-D3">ABC-D3</a>}.
 * `data-item-type` is absent on links written before docs existed, which are
 * therefore read as tasks. Parsing the saved HTML (rather than the editor's
 * transient state) means every write path — the Livewire editor, the MCP/API —
 * is covered uniformly, mirroring {@see MentionParser}.
 */
class InlineReferenceParser
{
    /**
     * The item kinds a reference may point at, keyed by their `data-item-type`.
     *
     * @var array<string, class-string<Model&Referenceable>>
     */
    private const array ITEM_TYPES = [
        'task' => Task::class,
        'doc' => Doc::class,
    ];

    /**
     * The items referenced in the given rich text, in first-seen order. Ids that
     * are malformed, of an unknown kind, or that no longer resolve to an item are
     * dropped, so a stale reference simply links nowhere instead of breaking the
     * sync.
     *
     * @return Collection<int, Model&Referenceable>
     */
    public static function targetsIn(?string $html): Collection
    {
        return new Collection(self::resolve(self::parse($html)));
    }

    /**
     * Load the referenced items, one query per kind.
     *
     * @param  array<string, list<int>>  $idsByType
     * @return list<Model&Referenceable>
     */
    private static function resolve(array $idsByType): array
    {
        $items = [];

        foreach ($idsByType as $type => $ids) {
            foreach (self::ITEM_TYPES[$type]::query()->whereKey($ids)->get() as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * The distinct referenced ids in the given rich text, grouped by item kind.
     *
     * @return array<string, list<int>>
     */
    private static function parse(?string $html): array
    {
        $html ??= '';

        // Cheap bail-out: skip the DOM round-trip when there is nothing to parse.
        if (! str_contains($html, 'data-type="reference"')) {
            return [];
        }

        $document = HTMLDocument::createFromString('<div>'.$html.'</div>', LIBXML_NOERROR);

        $idsByType = [];

        foreach ($document->querySelectorAll('a[data-type="reference"]') as $node) {
            $type = $node->getAttribute('data-item-type') ?: 'task';
            $id = (int) $node->getAttribute('data-id');

            if ($id > 0 && isset(self::ITEM_TYPES[$type])) {
                $idsByType[$type][$id] = $id;
            }
        }

        return array_map(array_values(...), $idsByType);
    }
}
