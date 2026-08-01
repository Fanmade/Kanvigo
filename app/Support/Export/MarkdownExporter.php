<?php

namespace App\Support\Export;

use App\Models\Doc;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Support\Export\Converters\InlineNodeConverter;
use App\Support\Export\Converters\StrikethroughConverter;
use App\Support\RichTextSanitizer;
use App\Support\VariableSubstitutor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Renders a single task or doc as a Markdown document: optional YAML
 * front-matter, the title as the top-level heading, then the stored rich text
 * converted to Markdown.
 *
 * This is the concrete renderer the export feature ships with — deliberately not
 * behind a format interface (docs/adr/0002-export-has-no-format-abstraction.md).
 * The HTML conversion is `league/html-to-markdown` with our own converters
 * registered for cross-references, mentions, variable usages and images
 * (docs/adr/0003-html-to-markdown-library.md).
 */
class MarkdownExporter
{
    /**
     * The longest a filename stem (everything before `.md`) may get. Long titles
     * are truncated rather than refused: the reference comes first, so what
     * survives still identifies the item.
     */
    private const int FILENAME_LENGTH = 60;

    public function __construct(
        private readonly RichTextSanitizer $sanitizer,
        private readonly VariableSubstitutor $variables,
    ) {}

    /**
     * The full Markdown document for one item.
     */
    public function render(Task|Doc $item, ExportOptions $options): string
    {
        $sections = [];

        if ($options->metadata) {
            $sections[] = $this->frontMatter($item);
        }

        $sections[] = '# '.$item->title;

        $body = $this->body($item);

        if ($body !== '') {
            $sections[] = $body;
        }

        foreach ($options->descendants ? $this->subtree($item, $options) : [] as $descendant) {
            $sections[] = $this->headingFor($descendant['item'], $descendant['level']);

            $metadata = $this->inlineMetadata($descendant['item']);

            if ($metadata !== '') {
                $sections[] = $metadata;
            }

            $body = $this->body($descendant['item']);

            if ($body !== '') {
                $sections[] = $body;
            }
        }

        return implode("\n\n", $sections)."\n";
    }

    /**
     * The item's subtree, depth-first in the order the board shows it, flattened
     * with each entry's level relative to the exported item (a direct child is
     * level 1).
     *
     * Filtered items take their own subtree with them: what hangs below a
     * canceled task is canceled work too, and a doc nested under a hidden draft
     * is not promoted into the reader's view.
     *
     * The tree is loaded in one query per kind, so the walk itself costs nothing
     * however wide or deep the subtree is.
     *
     * @return list<array{item: Task|Doc, level: int}>
     */
    public function subtree(Task|Doc $root, ExportOptions $options): array
    {
        $childrenByParent = $root instanceof Task
            ? $this->taskChildren($root)
            : $this->docChildren($root);

        $flattened = [];

        $walk = function (Task|Doc $item, int $level) use (&$walk, &$flattened, $childrenByParent, $options): void {
            foreach ($childrenByParent[$item->getKey()] ?? [] as $child) {
                if (! $this->includes($child, $options)) {
                    continue;
                }

                $flattened[] = ['item' => $child, 'level' => $level];

                if ($options->depth === null || $level < $options->depth) {
                    $walk($child, $level + 1);
                }
            }
        };

        $walk($root, 1);

        return $flattened;
    }

    /**
     * The download filename: the reference first so exports of different items
     * sort together and never collide, then a transliterated slug of the title.
     */
    public function filename(Task|Doc $item): string
    {
        $slug = Str::slug(Str::ascii($item->title));

        $stem = $slug === '' ? $item->reference : $item->reference.'-'.$slug;

        return Str::lower(rtrim(Str::limit($stem, self::FILENAME_LENGTH, ''), '-')).'.md';
    }

    /**
     * The stored rich text as Markdown. It goes through the same read-time
     * pipeline the page uses — sanitised, then variable usages resolved to what
     * they currently stand for — so the export says what the reader sees.
     */
    private function body(Task|Doc $item): string
    {
        $html = $item instanceof Task ? (string) $item->description : (string) $item->body;

        if (trim($html) === '') {
            return '';
        }

        $html = $this->variables->substitute(
            $this->sanitizer->sanitize($html),
            $item->project->short_name,
        );

        return trim($this->converter()->convert($html));
    }

    /**
     * The task's whole subtree in one recursive query, grouped by parent and
     * ordered the way the subtask lists order it.
     *
     * @return array<int, list<Task>>
     */
    private function taskChildren(Task $root): array
    {
        $descendants = $root->descendants()
            ->with(['taskType', 'assignees', 'tags', 'project'])
            ->get()
            ->sortBy('task_number');

        $grouped = [];

        foreach ($descendants as $task) {
            $grouped[(int) $task->parent_id][] = $task;
        }

        return $grouped;
    }

    /**
     * The doc's subtree, grouped by parent. Docs nest only a few levels deep, so
     * the project's docs are loaded in one query and grouped in memory rather
     * than walked with a recursive one.
     *
     * @return array<int, list<Doc>>
     */
    private function docChildren(Doc $root): array
    {
        $docs = Doc::query()
            ->with(['tags', 'project'])
            ->where('project_id', $root->project_id)
            ->orderBy('position')
            ->orderBy('doc_number')
            ->get();

        $grouped = [];

        foreach ($docs as $doc) {
            if ($doc->parent_id !== null) {
                $grouped[$doc->parent_id][] = $doc;
            }
        }

        return $grouped;
    }

    /**
     * Whether a descendant belongs in this export. Canceled tasks are noise in a
     * document unless explicitly asked for; a draft doc is invisible to most
     * readers, so it needs both the option and the viewer's own right to see it.
     */
    private function includes(Task|Doc $item, ExportOptions $options): bool
    {
        if ($item instanceof Task) {
            return $options->canceled || ! $item->isCanceled();
        }

        return $item->is_public || ($options->drafts && Gate::allows('view', $item));
    }

    /**
     * A descendant's heading, its level mirroring its depth in the tree. Markdown
     * stops at six levels while tasks nest without limit, so anything deeper
     * stays at `######` — a flattened tail is a better failure than an invalid
     * heading.
     */
    private function headingFor(Task|Doc $item, int $level): string
    {
        return str_repeat('#', min($level + 1, 6)).' '.$item->title;
    }

    /**
     * The one-line summary under a descendant's heading. YAML front-matter is
     * only legal at the top of a file, so everything below the root says its
     * piece inline instead: `*ABC-4 · ToDo · Feature · @ben · #export*`.
     */
    private function inlineMetadata(Task|Doc $item): string
    {
        $parts = [$item->reference];

        if ($item instanceof Task) {
            $parts[] = $item->isCanceled() ? __('Canceled') : $item->status->value;

            if ($item->taskType !== null) {
                $parts[] = (string) $item->taskType->name;
            }
        } elseif (! $item->is_public) {
            $parts[] = __('Draft');
        }

        foreach ($this->names($item instanceof Task ? $item->assignees : new Collection) as $assignee) {
            $parts[] = '@'.$assignee;
        }

        foreach ($this->names($item->tags) as $tag) {
            $parts[] = '#'.$tag;
        }

        return '*'.implode(' · ', $parts).'*';
    }

    /**
     * The configured HTML-to-Markdown converter. Built per export because the
     * converters carry the instance's base URL, and cheap enough not to cache.
     */
    private function converter(): HtmlConverter
    {
        // Options must go through the HtmlConverter constructor: building from a
        // bare Environment skips the library's defaults, and its own converters
        // read their styles from config (an unset italic_style silently renders
        // <em> as plain text).
        $converter = new HtmlConverter([
            'header_style' => 'atx',
            'list_item_style' => '-',
            'hard_break' => true,
            'use_autolinks' => false,
        ]);

        $environment = $converter->getEnvironment();
        $environment->addConverter(new InlineNodeConverter(url('/')));
        $environment->addConverter(new StrikethroughConverter);
        // Tables are opt-in in this library.
        $environment->addConverter(new TableConverter);

        return $converter;
    }

    /**
     * The YAML front-matter block. Empty fields are omitted rather than emitted
     * as blanks, so a small task exports a small header.
     */
    private function frontMatter(Task|Doc $item): string
    {
        $fields = $item instanceof Task ? $this->taskFields($item) : $this->docFields($item);

        $lines = ['---'];

        foreach ($fields as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $lines[] = $key.': '.(is_array($value) ? $this->sequence($value) : $this->scalar($value));
        }

        $lines[] = '---';

        return implode("\n", $lines);
    }

    /**
     * @return array<string, string|list<string>|null>
     */
    private function taskFields(Task $task): array
    {
        $relationships = $task->relationshipReferences();

        return [
            'reference' => $task->reference,
            'title' => $task->title,
            'url' => $this->url($task),
            'status' => $task->status->value,
            'type' => $task->taskType?->name,
            'priority' => $task->priority->name,
            'tags' => $this->names($task->tags),
            'assignees' => $this->names($task->assignees),
            'due_date' => $task->due_date?->toDateString(),
            'parent' => $task->parent?->reference,
            'blocked_by' => $relationships['blocked_by'] ?? [],
            'blocks' => $relationships['blocks'] ?? [],
            'exported_at' => $this->exportedAt(),
        ];
    }

    /**
     * A doc has no status, priority or dependencies; what it does have is a
     * publication state, which decides who can see it at all.
     *
     * @return array<string, string|list<string>|null>
     */
    private function docFields(Doc $doc): array
    {
        return [
            'reference' => $doc->reference,
            'title' => $doc->title,
            'url' => $this->url($doc),
            'state' => $doc->is_public ? 'published' : 'draft',
            'tags' => $this->names($doc->tags),
            'parent' => $doc->parent?->reference,
            'exported_at' => $this->exportedAt(),
        ];
    }

    /**
     * The item's absolute address on this instance. Built from the app's URL
     * generator, never from the reference and a guessed domain — Kanvigo is
     * self-hosted, so the reference alone does not identify a host.
     */
    private function url(Task|Doc $item): string
    {
        return $item instanceof Task
            ? route('task.show', ['short_name' => $item->project->short_name, 'task_number' => $item->task_number])
            : route('doc.show', ['short_name' => $item->project->short_name, 'doc_number' => $item->doc_number]);
    }

    /**
     * When the export was taken, in the application's configured timezone and
     * carrying its offset, so a file read months later still says when it was.
     */
    private function exportedAt(): string
    {
        return now()->toIso8601String();
    }

    /**
     * The `name` of each model in a relation, as a plain list.
     *
     * @template TModel of Tag|User
     *
     * @param  Collection<int, TModel>  $models
     * @return list<string>
     */
    private function names(Collection $models): array
    {
        return array_values(array_map(static fn (Tag|User $model): string => (string) $model->name, $models->all()));
    }

    /**
     * A YAML flow sequence, e.g. `[a, b]`.
     *
     * @param  list<string>  $values
     */
    private function sequence(array $values): string
    {
        return '['.implode(', ', array_map($this->scalar(...), $values)).']';
    }

    /**
     * A YAML scalar. Everything that is not plainly safe is double-quoted, which
     * keeps titles containing colons, quotes or leading punctuation parseable.
     */
    private function scalar(string $value): string
    {
        $plain = preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.\-\/:+@]*$/', $value) === 1
            // A colon only ends a YAML key when a space follows it, which is why
            // a URL or a timestamp offset needs no quoting but "Export: docs" does.
            && ! str_contains($value, ': ')
            && ! str_ends_with($value, ':');

        if ($plain) {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\"'], $value).'"';
    }
}
