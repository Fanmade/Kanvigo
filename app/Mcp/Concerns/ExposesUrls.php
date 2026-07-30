<?php

namespace App\Mcp\Concerns;

use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * Exposes the absolute web URL of an item to the MCP tools. References alone
 * ("PROJ-42") carry no domain, so a client asked for a link has to invent one;
 * returning the URL built from this instance's own configuration removes the
 * guesswork.
 */
trait ExposesUrls
{
    /**
     * The absolute URL of the item's page on this instance.
     */
    protected function itemUrl(Task|Doc|Project $item): string
    {
        return match (true) {
            $item instanceof Task => route('task.show', [
                'short_name' => $item->project->short_name,
                'task_number' => $item->task_number,
            ]),
            $item instanceof Doc => route('doc.show', [
                'short_name' => $item->project->short_name,
                'doc_number' => $item->doc_number,
            ]),
            $item instanceof Project => route('project.show', ['short_name' => $item->short_name]),
        };
    }

    /**
     * The output-schema field matching {@see itemUrl()}.
     */
    protected function urlSchema(JsonSchema $schema, string $item = 'item'): Type
    {
        return $schema->string()
            ->description('The absolute URL of the '.$item.' on this instance. Use it verbatim when linking; never construct a URL from the reference.')
            ->required();
    }
}
