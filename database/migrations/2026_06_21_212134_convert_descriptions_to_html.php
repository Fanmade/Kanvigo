<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Descriptions move from Markdown to HTML storage (to back the Flux rich-text
 * editor). Existing Markdown is converted to HTML with the exact pipeline the
 * renderer used until now, so content renders identically. The original Markdown
 * is preserved in a temporary `description_markdown` column so the change is
 * fully reversible (HTML -> Markdown is otherwise lossy).
 */
return new class extends Migration
{
    /**
     * The tables whose `description` column is converted.
     *
     * @var list<string>
     */
    private array $tables = ['tasks', 'projects'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, static function (Blueprint $blueprint): void {
                $blueprint->text('description_markdown')->nullable()->after('description');
            });

            DB::table($table)
                ->whereNotNull('description')
                ->where('description', '!=', '')
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($table): void {
                    foreach ($rows as $row) {
                        DB::table($table)->where('id', $row->id)->update([
                            'description_markdown' => $row->description,
                            'description' => $this->toHtml($row->description),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            DB::table($table)
                ->whereNotNull('description_markdown')
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($table): void {
                    foreach ($rows as $row) {
                        DB::table($table)->where('id', $row->id)->update([
                            'description' => $row->description_markdown,
                        ]);
                    }
                });

            Schema::table($table, static function (Blueprint $blueprint): void {
                $blueprint->dropColumn('description_markdown');
            });
        }
    }

    /**
     * Convert Markdown to HTML using the same options and inline-image link
     * post-processing the old Markdown renderer applied, so migrated content
     * matches what users already saw.
     */
    private function toHtml(string $markdown): string
    {
        $html = Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        // Open embedded image links (a thumbnail linking to its full-size image)
        // in a new tab instead of navigating away.
        /** @noinspection HtmlUnknownTarget */
        return (string) preg_replace(
            '/<a href="([^"]*)">(\s*<img\b)/',
            '<a href="$1" target="_blank" rel="noopener noreferrer">$2',
            $html,
        );
    }
};
