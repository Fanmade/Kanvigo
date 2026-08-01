<?php

use App\Livewire\Tasks\TaskView;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Export\ExportOptions;
use App\Support\Export\MarkdownExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->member = userWithRole($this->project, 'member');
    $this->actingAs($this->member);

    $this->task = Task::factory()->for($this->project)->create([
        'title' => 'Export functionality',
        'description' => '<p>The item itself.</p>',
    ]);
});

/** A comment on the item, authored by the given user at the given moment. */
function comment(Task $on, User $author, string $body, string $at = '2026-08-02 09:00', ?Comment $replyTo = null): Comment
{
    $comment = $on->comments()->create([
        'user_id' => $author->id,
        'body' => $body,
        'parent_id' => $replyTo?->id,
    ]);

    $comment->forceFill(['created_at' => Carbon::parse($at)])->save();

    return $comment;
}

/** The rendered Markdown for a task, with the discussion included. */
function exportedWithComments(Task $task, bool $descendants = false): string
{
    return app(MarkdownExporter::class)->render(
        $task->fresh(),
        new ExportOptions(metadata: false, descendants: $descendants, comments: true),
    );
}

describe('the discussion', function () {
    it('stays out unless it is asked for', function () {
        comment($this->task, $this->member, '<p>Worth doing.</p>');

        $markdown = app(MarkdownExporter::class)->render($this->task, new ExportOptions(metadata: false));

        expect($markdown)->not->toContain('Worth doing.')
            ->and($markdown)->not->toContain('Comments');
    });

    it('renders under its own heading after the item body, oldest first', function () {
        $ada = User::factory()->create(['name' => 'Ada']);
        comment($this->task, $this->member, '<p>Second thought.</p>', at: '2026-08-02 11:00');
        comment($this->task, $ada, '<p>First thought.</p>', at: '2026-08-02 09:00');

        $markdown = exportedWithComments($this->task);

        expect($markdown)->toContain('## Comments')
            ->and(strpos($markdown, 'The item itself.'))->toBeLessThan(strpos($markdown, '## Comments'))
            ->and(strpos($markdown, 'First thought.'))->toBeLessThan(strpos($markdown, 'Second thought.'));
    });

    it('names the author and when they wrote', function () {
        $ada = User::factory()->create(['name' => 'Ada']);
        comment($this->task, $ada, '<p>Worth doing.</p>', at: '2026-08-02 09:15');

        expect(exportedWithComments($this->task))->toContain('**Ada** · 2026-08-02 09:15');
    });

    it('converts a comment through the same renderer as any other content', function () {
        $other = Task::factory()->for($this->project)->create();
        comment($this->task, $this->member, '<p>See '.inlineReference($other).' — <strong>bold</strong> and 2 * 3.</p>');

        $url = route('task.show', ['short_name' => 'ABC', 'task_number' => $other->task_number]);

        expect(exportedWithComments($this->task))
            ->toContain('['.$other->reference.']('.$url.')')
            ->toContain('**bold**')
            ->toContain('2 \* 3');
    });

    it('quotes a reply under the comment it answers', function () {
        $root = comment($this->task, $this->member, '<p>Worth doing.</p>', at: '2026-08-02 09:00');
        comment($this->task, $this->member, '<p>Agreed.</p>', at: '2026-08-02 09:30', replyTo: $root);

        $markdown = exportedWithComments($this->task);

        expect($markdown)->toContain('> Agreed.')
            ->and($markdown)->not->toContain('> Worth doing.');
    });

    it('keeps a deleted comment as a tombstone so its replies still make sense', function () {
        $root = comment($this->task, $this->member, '<p>Removed later.</p>', at: '2026-08-02 09:00');
        comment($this->task, $this->member, '<p>Answering it.</p>', at: '2026-08-02 09:30', replyTo: $root);
        $root->forceFill(['is_deleted' => true, 'body' => ''])->save();

        $markdown = exportedWithComments($this->task);

        expect($markdown)->toContain('*deleted*')
            ->and($markdown)->not->toContain('Removed later.')
            ->and($markdown)->toContain('> Answering it.');
    });

    it('includes the discussion on descendants too', function () {
        $child = Task::factory()->for($this->project)->childOf($this->task)->create(['title' => 'Nested work']);
        comment($child, $this->member, '<p>About the subtask.</p>');

        $markdown = exportedWithComments($this->task, descendants: true);

        // The child sits at "##", so its discussion heading is one level deeper.
        expect($markdown)->toContain('### Comments')
            ->and($markdown)->toContain('About the subtask.');
    });

    it('leaves the heading out for an item nobody commented on', function () {
        $child = Task::factory()->for($this->project)->childOf($this->task)->create();
        comment($child, $this->member, '<p>Only here.</p>');

        $markdown = exportedWithComments($this->task, descendants: true);

        expect(substr_count($markdown, 'Comments'))->toBe(1);
    });
});

describe('the control', function () {
    it('stays hidden until something in the export has a comment', function () {
        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number])
            ->call('startExport')
            ->assertDontSeeHtml('data-test="export-comments"');
    });

    it('appears once the item has one, off by default', function () {
        comment($this->task, $this->member, '<p>Worth doing.</p>');

        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number])
            ->call('startExport')
            ->assertSeeHtml('data-test="export-comments"')
            ->assertSet('exportComments', false);
    });

    it('appears when only a descendant has one, once descendants are included', function () {
        $child = Task::factory()->for($this->project)->childOf($this->task)->create();
        comment($child, $this->member, '<p>Only here.</p>');

        $component = Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number])
            ->call('startExport');

        $component->assertDontSeeHtml('data-test="export-comments"')
            ->set('exportDescendants', true)
            ->assertSeeHtml('data-test="export-comments"');
    });

    it('exports the discussion and records the choice in the audit event', function () {
        comment($this->task, $this->member, '<p>Worth doing.</p>');

        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number])
            ->call('startExport')
            ->set('exportComments', true)
            ->call('copyExport')
            ->assertDispatched('export-copied', function (string $_event, array $params): bool {
                return str_contains($params['markdown'], 'Worth doing.');
            });

        $event = json_decode((string) DB::table('audit_outbox')->orderByDesc('id')->value('event'), true);

        expect($event['metadata']['comments'])->toBeTrue();
    });
});
