<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The rich-text columns that may hold up to RichTextSanitizer::MAX_LENGTH
     * characters.
     *
     * @var array<string, string>
     */
    private const array COLUMNS = [
        'docs' => 'body',
        'notes' => 'body',
        'projects' => 'description',
        'tasks' => 'description',
    ];

    /**
     * MySQL/MariaDB cap TEXT at 64 KB, below the rich-text length limit once
     * multibyte characters count, so widen those columns to MEDIUMTEXT. TEXT is
     * already unbounded on SQLite and PostgreSQL, which need no change.
     */
    public function up(): void
    {
        if (! $this->hasBoundedText()) {
            return;
        }

        foreach (self::COLUMNS as $table => $column) {
            Schema::table($table, static function (Blueprint $blueprint) use ($column): void {
                $blueprint->mediumText($column)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (! $this->hasBoundedText()) {
            return;
        }

        foreach (self::COLUMNS as $table => $column) {
            Schema::table($table, static function (Blueprint $blueprint) use ($column): void {
                $blueprint->text($column)->nullable()->change();
            });
        }
    }

    private function hasBoundedText(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
