<?php

namespace App\Support\Export;

use App\Models\Comment;
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
        $descendants = $options->descendants ? $this->subtree($item, $options) : [];

        // The images are resolved for the document as a whole: one query for the
        // attachments it references, and one budget spent across all of them.
        $items = [$item, ...array_map(static fn (array $entry): Task|Doc => $entry['item'], $descendants)];
        $comments = $options->comments ? $this->commentsFor($items) : [];

        $images = new ExportImages($options->images);
        $images->prepare([
            ...array_map(fn (Task|Doc $each): string => $this->rawHtml($each), $items),
            ...array_map(static fn (Comment $comment): string => $comment->body, array_merge(...array_values($comments) ?: [[]])),
        ]);

        $converter = $this->converter($images);
        $sections = [];

        if ($options->metadata) {
            $sections[] = $this->frontMatter($item);
        }

        $sections[] = '# '.$item->title;

        $body = $this->body($item, $converter);

        if ($body !== '') {
            $sections[] = $body;
        }

        $sections = [...$sections, ...$this->commentSections($item, 1, $comments, $converter)];

        foreach ($descendants as $descendant) {
            $sections[] = $this->headingFor($descendant['item'], $descendant['level']);

            $metadata = $this->inlineMetadata($descendant['item']);

            if ($metadata !== '') {
                $sections[] = $metadata;
            }

            $body = $this->body($descendant['item'], $converter);

            if ($body !== '') {
                $sections[] = $body;
            }

            $sections = [
                ...$sections,
                ...$this->commentSections($descendant['item'], $descendant['level'] + 1, $comments, $converter),
            ];
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
     *
     * The optional date prefix sorts a folder of exports by when they were taken
     * rather than by which item they came from, and sits outside the length cap
     * so a long title can never eat the date.
     */
    public function filename(Task|Doc $item, bool $datePrefix = false): string
    {
        $slug = Str::slug(Str::ascii($item->title));

        $stem = $slug === '' ? $item->reference : $item->reference.'-'.$slug;
        $stem = Str::lower(rtrim(Str::limit($stem, self::FILENAME_LENGTH, ''), '-'));

        return ($datePrefix ? now()->format('Y-m-d').'_' : '').$stem.'.md';
    }

    /**
     * The stored rich text as Markdown. It goes through the same read-time
     * pipeline the page uses — sanitised, then variable usages resolved to what
     * they currently stand for — so the export says what the reader sees.
     */
    private function body(Task|Doc $item, HtmlConverter $converter): string
    {
        $html = $this->rawHtml($item);

        if (trim($html) === '') {
            return '';
        }

        $html = $this->variables->substitute(
            $this->sanitizer->sanitize($html),
            $item->project->short_name,
        );

        return trim($converter->convert($html));
    }

    /**
     * The comments on every item in the export, keyed by "type:id" and ordered
     * oldest first. Loaded in one query per kind of item, so a subtree export
     * with a discussion on every task still costs a single round trip.
     *
     * @param  list<Task|Doc>  $items
     * @return array<string, list<Comment>>
     */
    private function commentsFor(array $items): array
    {
        $idsByType = [];

        foreach ($items as $item) {
            $idsByType[$item->getMorphClass()][] = $item->getKey();
        }

        $comments = [];

        foreach ($idsByType as $type => $ids) {
            $loaded = Comment::query()
                ->with('user')
                ->where('commentable_type', $type)
                ->whereIn('commentable_id', $ids)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            foreach ($loaded as $comment) {
                $comments[$type.':'.$comment->commentable_id][] = $comment;
            }
        }

        return $comments;
    }

    /**
     * One item's discussion: a heading a level below the item's own, then each
     * thread oldest first. Replies are quoted under the comment they answer, so
     * the shape of the conversation survives in plain text.
     *
     * A deleted comment that still holds replies keeps its place as a tombstone —
     * dropping it would leave its answers hanging in mid-air.
     *
     * @param  array<string, list<Comment>>  $comments
     * @return list<string>
     */
    private function commentSections(Task|Doc $item, int $level, array $comments, HtmlConverter $converter): array
    {
        $own = $comments[$item->getMorphClass().':'.$item->getKey()] ?? [];

        if ($own === []) {
            return [];
        }

        $replies = [];

        foreach ($own as $comment) {
            if ($comment->parent_id !== null) {
                $replies[$comment->parent_id][] = $comment;
            }
        }

        $sections = [str_repeat('#', min($level + 1, 6)).' '.__('Comments')];

        foreach ($own as $comment) {
            if ($comment->parent_id !== null) {
                continue;
            }

            $shortName = $item->project->short_name;

            $sections = [...$sections, ...$this->commentBlock($comment, $shortName, $converter, quoted: false)];

            foreach ($replies[$comment->getKey()] ?? [] as $reply) {
                $sections = [...$sections, ...$this->commentBlock($reply, $shortName, $converter, quoted: true)];
            }
        }

        return $sections;
    }

    /**
     * A single comment: who said it and when, then what they said. A reply is
     * quoted whole, indentation being the only nesting Markdown offers inline.
     *
     * @param  string  $shortName  the surrounding project, so the comment's variable
     *                             usages resolve to what the page shows
     * @return list<string>
     */
    private function commentBlock(Comment $comment, string $shortName, HtmlConverter $converter, bool $quoted): array
    {
        $heading = '**'.$comment->authorName().'** · '.$comment->created_at?->format('Y-m-d H:i');

        if ($comment->is_deleted) {
            $body = '*'.__('deleted').'*';
        } else {
            $body = trim($converter->convert(
                $this->variables->substitute($this->sanitizer->sanitize($comment->body), $shortName),
            ));
        }

        $block = $body === '' ? [$heading] : [$heading, $body];

        return $quoted
            ? array_map(static fn (string $part): string => '> '.str_replace("\n", "\n> ", $part), $block)
            : $block;
    }

    /**
     * The item's stored rich text, exactly as saved.
     */
    private function rawHtml(Task|Doc $item): string
    {
        return $item instanceof Task ? (string) $item->description : (string) $item->body;
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
     * Whether a descendant belongs in this export. Canceled and archived tasks
     * are noise in a document unless explicitly asked for; a draft doc is
     * invisible to most readers, so it needs both the option and the viewer's own
     * right to see it.
     */
    private function includes(Task|Doc $item, ExportOptions $options): bool
    {
        if ($item instanceof Task) {
            return (! $item->isCanceled() || $options->canceled)
                && (! $item->isArchived() || $options->archived);
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

            if ($item->isArchived()) {
                $parts[] = __('Archived');
            }

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
     * The configured HTML-to-Markdown converter. Built once per export, because
     * its converters carry both the instance's base URL and the image decisions
     * for this particular export.
     */
    private function converter(ExportImages $images): HtmlConverter
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
        $environment->addConverter(new InlineNodeConverter(url('/'), $images));
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
