<?php

namespace App\Concerns;

use App\Contracts\Referenceable;
use App\Enums\ReferenceOrigin;
use App\Support\InlineReferenceParser;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps an item's cross-references in step with the #references written into its
 * rich text (KAN-441): writing "#ABC-42" in a doc body links the doc to that task
 * — and gives the task a backlink — while deleting the reference from the text
 * removes the link again.
 *
 * The reconciliation runs on every save from the stored, sanitized HTML — the one
 * place all write paths (Livewire editor, MCP/API) converge — mirroring how
 * {@see HasMentions} maintains the mention index. Only the links the text
 * produced are managed; curated links keep their {@see ReferenceOrigin::Manual}
 * origin and are left alone.
 *
 * Complements {@see HasReferences}, which the model uses for the links themselves.
 *
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements Referenceable
 */
trait SyncsInlineReferences
{
    public static function bootSyncsInlineReferences(): void
    {
        static::saved(static function (self $model): void {
            if ($model->wasRecentlyCreated || $model->wasChanged('description') || $model->wasChanged('body')) {
                $model->syncReferencesFromContent();
            }
        });
    }

    /**
     * Reconcile this item's inline links with the references its content
     * currently carries.
     */
    public function syncReferencesFromContent(): void
    {
        $this->syncInlineReferences(InlineReferenceParser::targetsIn($this->referenceContent()));
    }

    /**
     * The rich-text content to scan for references. A model carries one of the
     * description/body columns; the other is simply absent.
     */
    protected function referenceContent(): string
    {
        $html = '';

        foreach (['description', 'body'] as $attribute) {
            $value = $this->getAttribute($attribute);

            if (filled($value)) {
                $html .= ' '.$value;
            }
        }

        return $html;
    }
}
