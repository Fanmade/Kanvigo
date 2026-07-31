<?php

namespace App\Contracts;

use App\Concerns\IndexesVariableUsages;
use App\Models\VariableUsage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * An item whose rich-text content can name a project's variables — a task
 * description, doc body, comment or project description. It is what the usage
 * index is allowed to record against, which is how notes stay out: a note is
 * projectless, so it has no variable namespace to resolve against.
 *
 * {@see IndexesVariableUsages} implements all of it bar the namespace itself.
 */
interface UsesVariables
{
    /**
     * The project whose variable namespace this item's content resolves against,
     * or null when it has none (and therefore no usages to record).
     */
    public function variableNamespaceProjectId(): ?int;

    /**
     * The column holding this item's rich-text content — what a rename rewrites.
     */
    public function variableContentColumn(): string;

    /**
     * The rich-text content to scan for `[name]` usages.
     */
    public function variableContent(): ?string;

    /**
     * The recorded names this item's content uses.
     *
     * @return MorphMany<VariableUsage, covariant Model>
     */
    public function variableUsages(): MorphMany;

    /**
     * Reconcile the index with the names the current content uses.
     */
    public function syncVariableUsages(): void;
}
