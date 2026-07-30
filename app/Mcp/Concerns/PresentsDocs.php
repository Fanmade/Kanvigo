<?php

namespace App\Mcp\Concerns;

use App\Models\Attachment;
use App\Models\Doc;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * The shared doc representation for the MCP doc tools, so the read and write
 * shapes never drift. A doc is a project-scoped reference page addressed by
 * "PROJ-D<n>"; unlike a task it has no status, only a draft/published visibility.
 */
trait PresentsDocs
{
    use ExposesReferences;
    use ExposesUrls;
    use ResolvesAuthenticatedUser;

    /**
     * The full doc payload, returned by the get and write tools.
     *
     * @return array<string, mixed>
     */
    protected function docPayload(Doc $doc, User $user): array
    {
        $doc->loadMissing(['project', 'parent.project', 'children', 'tags', 'attachments']);

        return [
            ...$this->docListPayload($doc),
            'url' => $this->itemUrl($doc),
            'body' => $doc->body,
            'tags' => $doc->tags->pluck('name')->all(),
            'children' => $doc->children
                ->filter(static fn (Doc $child): bool => $user->can('view', $child))
                ->map(static fn (Doc $child): array => [
                    'reference' => $doc->project->short_name.'-D'.$child->doc_number,
                    'title' => $child->title,
                    'is_public' => $child->is_public,
                ])->values()->all(),
            ...$this->referencePayload($doc, $user),
            'attachments' => $doc->attachments->map(static fn (Attachment $attachment): array => [
                'id' => $attachment->id,
                'name' => $attachment->name,
                'mime_type' => $attachment->mime_type,
                'is_inline' => $attachment->is_inline,
            ])->all(),
        ];
    }

    /**
     * The compact doc payload used when listing docs (no body, links or files).
     *
     * @return array<string, mixed>
     */
    protected function docListPayload(Doc $doc): array
    {
        return [
            'reference' => $doc->reference,
            'title' => $doc->title,
            'project' => $doc->project->short_name,
            'parent' => $doc->parent?->reference,
            'is_public' => $doc->is_public,
        ];
    }

    /**
     * The output-schema fields matching {@see docPayload()}.
     *
     * @return array<string, Type>
     */
    protected function docSchema(JsonSchema $schema): array
    {
        return [
            ...$this->docListSchema($schema),
            'url' => $this->urlSchema($schema, 'doc'),
            'body' => $schema->string()->nullable()->description('The doc body as HTML; may be null while the doc is empty.'),
            'tags' => $schema->array()->items($schema->string())->description('The tag names applied to the doc.')->required(),
            'children' => $schema->array()->items($schema->object([
                'reference' => $schema->string()->description('The nested doc reference, e.g. "PROJ-D4".')->required(),
                'title' => $schema->string()->description('The nested doc title.')->required(),
                'is_public' => $schema->boolean()->description('Whether the nested doc is published.')->required(),
            ]))->description('The docs nested directly under this one that the user may see.')->required(),
            ...$this->referenceSchema($schema),
            'attachments' => $schema->array()->items($schema->object([
                'id' => $schema->integer()->description('The attachment id; pass it to the get-attachment tool to read the file.')->required(),
                'name' => $schema->string()->description('The attachment file name.')->required(),
                'mime_type' => $schema->string()->nullable()->description('The attachment MIME type; may be null.'),
                'is_inline' => $schema->boolean()->description('Whether the attachment is embedded inline in the body.')->required(),
            ]))->description('The files attached to the doc, including inline body images.')->required(),
        ];
    }

    /**
     * The output-schema fields matching {@see docListPayload()}.
     *
     * @return array<string, Type>
     */
    protected function docListSchema(JsonSchema $schema): array
    {
        return [
            'reference' => $schema->string()->description('The doc reference, e.g. "PROJ-D3".')->required(),
            'title' => $schema->string()->description('The doc title.')->required(),
            'project' => $schema->string()->description('The short name of the project the doc belongs to.')->required(),
            'parent' => $schema->string()->nullable()->description('The reference of the doc this one is nested under, or null when it is top-level.'),
            'is_public' => $schema->boolean()->description('Whether the doc is published to the project. A draft (false) is visible only to members who may edit docs.')->required(),
        ];
    }
}
