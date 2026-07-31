<?php

namespace App\Models;

use Database\Factories\VariableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * A named, project-scoped stand-in for a fact that appears in many places or is
 * not yet decided — `[main_protagonist]` resolving to "Robin Hood". The variable
 * is the single source of truth for that fact; the places it appears merely name
 * it, and are resolved when the content is read (see
 * docs/adr/0001-project-variables.md).
 *
 * A variable with no value is *unset* — a normal state, and the point of the
 * feature while planning: its usages render the name, a visible hole in the
 * prose. There is therefore no deliberately blank value; an empty one is null.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string|null $description
 * @property string|null $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
// project_id comes from the owning project relationship, not from user input,
// so it stays out of the mass-assignable allow-list.
#[Fillable(['name', 'description', 'value'])]
class Variable extends Model
{
    /** @use HasFactory<VariableFactory> */
    use HasFactory;

    /**
     * Normalize on the way in, whichever entry point wrote the row: names are
     * stored trimmed and lower-cased (so a plain unique index dedupes them), and
     * a blank value or description is stored as null — "unset" has exactly one
     * representation.
     */
    protected static function booted(): void
    {
        static::saving(static function (self $variable): void {
            $variable->name = self::normalizeName($variable->name);
            $variable->value = self::blankToNull($variable->value);
            $variable->description = self::blankToNull($variable->description);
        });
    }

    /**
     * A variable name: at least two characters, starting with a letter, then
     * lowercase letters, digits, underscores and hyphens. Strict on purpose —
     * it is what keeps footnote markers like [1] and [i], and other bracketed
     * prose, from ever being mistaken for a variable usage.
     */
    public const string NAME_PATTERN = '/^[a-z][a-z0-9_-]+$/';

    /**
     * Whether the given name is a syntactically valid variable name, once
     * normalized. The single place the pattern is applied.
     */
    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, self::normalizeName($name)) === 1;
    }

    /**
     * Validation rules for a variable name within a project, including
     * uniqueness. Pass the variable being edited to exempt it from the
     * uniqueness check when renaming.
     *
     * @return list<mixed>
     */
    public static function nameRules(Project $project, ?self $ignoring = null): array
    {
        $unique = Rule::unique('variables', 'name')->where('project_id', $project->getKey());

        return [
            'required',
            'string',
            'max:255',
            'regex:'.self::NAME_PATTERN,
            $ignoring === null ? $unique : $unique->ignore($ignoring->getKey()),
        ];
    }

    /**
     * Validation rules for a variable's value. Values are plain single-line
     * text, and an empty one means *unset* rather than blank.
     *
     * @return list<string>
     */
    public static function valueRules(): array
    {
        return ['nullable', 'string', 'max:255'];
    }

    /**
     * Validation rules for a variable's optional description.
     *
     * @return list<string>
     */
    public static function descriptionRules(): array
    {
        return ['nullable', 'string', 'max:255'];
    }

    /**
     * Trim and lower-case a name, so lookups and storage agree without needing a
     * case-insensitive index. Callers validate the result with
     * {@see isValidName()}.
     */
    public static function normalizeName(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /**
     * The project this variable belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Whether the variable currently stands for something. An unset variable
     * still renders — showing its name — so this is a display concern, not an
     * error state.
     */
    public function isUnset(): bool
    {
        return $this->value === null;
    }

    /**
     * Trim the given text, collapsing a blank result to null.
     */
    protected static function blankToNull(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }
}
