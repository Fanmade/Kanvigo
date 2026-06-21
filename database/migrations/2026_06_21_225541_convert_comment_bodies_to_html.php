<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Comment bodies move from Markdown to HTML storage (to back the Flux rich-text
 * editor), mirroring the task/project description migration. Existing Markdown is
 * converted with the same pipeline the renderer used, and the original is kept in
 * a temporary `body_markdown` column so the change is reversible. Tombstoned
 * (deleted) comments have an empty body and are skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', static function (Blueprint $blueprint): void {
            $blueprint->text('body_markdown')->nullable()->after('body');
        });

        DB::table('comments')
            ->where('body', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('comments')->where('id', $row->id)->update([
                        'body_markdown' => $row->body,
                        'body' => $this->toHtml($row->body),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('comments')
            ->whereNotNull('body_markdown')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('comments')->where('id', $row->id)->update([
                        'body' => $row->body_markdown,
                    ]);
                }
            });

        Schema::table('comments', static function (Blueprint $blueprint): void {
            $blueprint->dropColumn('body_markdown');
        });
    }

    /**
     * Convert Markdown to HTML using the same options the old Markdown renderer
     * applied, so migrated comments match what users already saw.
     */
    private function toHtml(string $markdown): string
    {
        $html = Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        /** @noinspection HtmlUnknownTarget */
        return (string) preg_replace(
            '/<a href="([^"]*)">(\s*<img\b)/',
            '<a href="$1" target="_blank" rel="noopener noreferrer">$2',
            $html,
        );
    }
};
