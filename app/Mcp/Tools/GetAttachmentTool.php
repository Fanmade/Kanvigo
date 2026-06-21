<?php

namespace App\Mcp\Tools;

use App\Models\Attachment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Gets the binary content of an attachment by its id, including inline images embedded in a project or task description. Image and audio attachments are returned as viewable content; other file types return their metadata. Attachment ids are listed by the get-project and get-task tools. Only attachments in projects the authenticated user is a member of are accessible.')]
#[IsReadOnly]
class GetAttachmentTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'id' => ['required', 'integer'],
        ], [
            'id.required' => 'You must provide the attachment id. Attachment ids are listed by the get-project and get-task tools.',
        ]);

        $attachment = Attachment::query()->whereKey($validated['id'])->first();

        if ($attachment === null || ! $request->user()->can('view', $attachment)) {
            return Response::error('No attachment with id "'.$validated['id'].'" exists, or you do not have access to it.');
        }

        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->path)) {
            return Response::error('The attachment file is no longer available.');
        }

        $mimeType = (string) $attachment->mime_type;
        $contents = $disk->get($attachment->path);

        if (str_starts_with($mimeType, 'image/')) {
            return Response::image($contents, $mimeType);
        }

        if (str_starts_with($mimeType, 'audio/')) {
            return Response::audio($contents, $mimeType);
        }

        return Response::text('Attachment "'.$attachment->name.'" ('.($mimeType !== '' ? $mimeType : 'unknown type').', '.$attachment->size.' bytes) cannot be displayed inline. Only image and audio attachments are viewable; download it from '.$attachment->downloadUrl().' instead.');
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->description('The attachment id, as listed by the get-project and get-task tools.')
                ->required(),
        ];
    }
}
