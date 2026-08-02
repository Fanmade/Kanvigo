<?php

namespace App\Support\Export;

use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Support\Export\Exceptions\ProjectTooLargeToExport;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The whole of a project as one archive: every top-level task with its subtree,
 * and every doc tree, each item its own file.
 *
 * Always a bundle — a project flattened into a single document would be
 * unreadable — and always a ZIP. The archive is built in the request, so its
 * size is bounded: past `kanvigo.export.max_project_items` the export is refused
 * with a clear message rather than tying up a web worker. That ceiling is where
 * a queued job would slot in if projects ever outgrow it.
 */
class ProjectExport
{
    public function __construct(private readonly ExportBundle $bundle) {}

    /**
     * The archive's files, as path => contents.
     *
     * @return array<string, string>
     *
     * @throws ProjectTooLargeToExport
     */
    public function files(Project $project, ExportOptions $options): array
    {
        $roots = $this->roots($project, $options);
        $this->assertWithinLimit($project, $roots, $options);

        return $this->bundle->projectFiles($roots, $this->asBundle($options));
    }

    /**
     * The archive itself, as bytes.
     *
     * @throws ProjectTooLargeToExport
     */
    public function zip(Project $project, ExportOptions $options): string
    {
        return $this->bundle->archive($this->files($project, $options));
    }

    /**
     * How many items the export would cover — what the size guard counts, and
     * what the dialog shows before anyone commits to it.
     */
    public function itemCount(Project $project, ExportOptions $options): int
    {
        $count = 0;

        foreach ($this->roots($project, $options) as $root) {
            $count += 1 + count($this->bundle->subtreeOf($root, $options));
        }

        return $count;
    }

    /**
     * The archive's name: the project's short name and title, e.g.
     * `abc-ironwood-ledger.zip`.
     */
    public function filename(Project $project, ExportOptions $options): string
    {
        $slug = Str::slug(Str::ascii($project->title));
        $stem = Str::lower($project->short_name.($slug === '' ? '' : '-'.$slug));

        return ($options->datePrefix ? now()->format('Y-m-d').'_' : '').$stem.'.zip';
    }

    /**
     * The trees the export covers: the project's top-level tasks, then the docs
     * the viewer may see. Drafts follow the same option they do everywhere else,
     * and a draft nobody may read never travels.
     *
     * @return list<Task|Doc>
     */
    private function roots(Project $project, ExportOptions $options): array
    {
        $tasks = $project->tasks()
            ->whereNull('parent_id')
            ->orderBy('task_number')
            ->get()
            ->filter(fn (Task $task): bool => $this->includes($task, $options))
            ->values()
            ->all();

        $docs = $project->docs()
            ->whereNull('parent_id')
            ->orderBy('position')
            ->orderBy('doc_number')
            ->get()
            ->filter(fn (Doc $doc): bool => $this->includes($doc, $options))
            ->values()
            ->all();

        return [...$tasks, ...$docs];
    }

    /**
     * Whether a tree's root belongs in the export. The subtree walk applies the
     * same rules below it; this is the same question asked one level up, where
     * there is no parent to ask it for us.
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
     * A project export is a bundle whatever the dialog says: one file per item,
     * every level, or the archive is a single unreadable wall of text.
     */
    private function asBundle(ExportOptions $options): ExportOptions
    {
        return new ExportOptions(
            metadata: $options->metadata,
            descendants: true,
            depth: null,
            canceled: $options->canceled,
            archived: $options->archived,
            drafts: $options->drafts,
            comments: $options->comments,
            bundle: true,
            layout: $options->layout,
            datePrefix: $options->datePrefix,
            format: $options->format,
            attachments: $options->attachments,
            images: $options->images,
        );
    }

    /**
     * @param  list<Task|Doc>  $roots
     *
     * @throws ProjectTooLargeToExport
     */
    private function assertWithinLimit(Project $project, array $roots, ExportOptions $options): void
    {
        $limit = (int) config('kanvigo.export.max_project_items');
        $count = 0;

        foreach ($roots as $root) {
            $count += 1 + count($this->bundle->subtreeOf($root, $this->asBundle($options)));
        }

        if ($count > $limit) {
            throw ProjectTooLargeToExport::of($project, $count, $limit);
        }
    }
}
