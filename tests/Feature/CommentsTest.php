<?php

use App\Livewire\Comments\CommentList;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->project = Project::factory()->create();
    joinProject($this->project, $this->member);
    $this->task = Task::factory()->for($this->project)->create();
    $this->subtask = Task::factory()->for($this->project)->childOf($this->task)->create();
});

it('uploads an image pasted into a comment as an inline attachment on the task', function () {
    $component = Livewire::actingAs($this->member)
        ->test(CommentList::class, ['commentable' => $this->task])
        ->set('inlineImage', UploadedFile::fake()->image('shot.png', 400, 300))
        ->call('addInlineImage')
        ->assertHasNoErrors();

    $attachment = $this->task->attachments()->where('is_inline', true)->first();

    expect($attachment)->not->toBeNull()
        ->and($attachment->is_inline)->toBeTrue();

    $component->assertReturned([
        'src' => $attachment->thumbnailUrl(absolute: false),
        'href' => $attachment->viewUrl(absolute: false),
    ]);
});

it('toasts and attaches nothing when an inline image is rejected (KAN-249)', function () {
    // A HEIC phone photo isn't accepted by the `image` rule; the editor must not
    // be left stuck on its spinner — it should toast and add no attachment.
    $component = Livewire::actingAs($this->member)
        ->test(CommentList::class, ['commentable' => $this->task])
        ->set('inlineImage', UploadedFile::fake()->create('photo.heic', 200, 'image/heic'))
        ->call('addInlineImage')
        ->assertHasNoErrors()
        ->assertReturned(null)
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger');

    expect($this->task->attachments()->where('is_inline', true)->count())->toBe(0)
        ->and($component->get('inlineImage'))->toBeNull();
});

it('lets a member comment on a task and logs the activity', function () {
    Livewire::actingAs($this->member)
        ->test(CommentList::class, ['commentable' => $this->task])
        ->set('body', 'Looks good to me')
        ->call('addComment')
        ->assertHasNoErrors()
        ->assertDispatched('comment-added');

    $comment = $this->task->comments()->first();

    expect($this->task->comments()->count())->toBe(1)
        ->and($comment->body)->toBe('Looks good to me')
        ->and($comment->user_id)->toBe($this->member->id)
        ->and($this->task->activities()->where('action', 'commented')->count())->toBe(1);
});

it('supports comments on projects and subtasks', function () {
    Livewire::actingAs($this->member)
        ->test(CommentList::class, ['commentable' => $this->project])
        ->set('body', 'Project note')
        ->call('addComment');

    Livewire::actingAs($this->member)
        ->test(CommentList::class, ['commentable' => $this->subtask])
        ->set('body', 'Subtask note')
        ->call('addComment');

    expect($this->project->comments()->count())->toBe(1)
        ->and($this->subtask->comments()->count())->toBe(1);
});

it('requires a comment body', function () {
    Livewire::actingAs($this->member)
        ->test(CommentList::class, ['commentable' => $this->task])
        ->call('addComment')
        ->assertHasErrors('body');

    expect($this->task->comments()->count())->toBe(0);
});

it('lets a member reply to a comment', function () {
    $comment = $this->task->comments()->create(['user_id' => $this->member->id, 'body' => 'Parent']);

    Livewire::actingAs($this->member)
        ->test(CommentList::class, ['commentable' => $this->task])
        ->call('startReply', $comment->id)
        ->set('replyBody', 'A reply')
        ->call('addReply')
        ->assertHasNoErrors();

    expect($comment->replies()->count())->toBe(1)
        ->and($comment->replies()->first()->body)->toBe('A reply');
});

it('keeps reply threads one level deep', function () {
    $root = $this->task->comments()->create(['user_id' => $this->member->id, 'body' => 'Root']);
    $reply = $this->task->comments()->create(['user_id' => $this->member->id, 'body' => 'Reply', 'parent_id' => $root->id]);

    Livewire::actingAs($this->member)
        ->test(CommentList::class, ['commentable' => $this->task])
        ->call('startReply', $reply->id)
        ->set('replyBody', 'Reply to a reply')
        ->call('addReply');

    // The new comment attaches to the root, not to the reply.
    expect($reply->replies()->count())->toBe(0)
        ->and($root->replies()->count())->toBe(2);
});

it('only lists top-level comments at the root', function () {
    $root = $this->task->comments()->create(['user_id' => $this->member->id, 'body' => 'Root']);
    $this->task->comments()->create(['user_id' => $this->member->id, 'body' => 'Child', 'parent_id' => $root->id]);

    $top = Livewire::actingAs($this->member)
        ->test(CommentList::class, ['commentable' => $this->task])
        ->instance()->comments();

    expect($top)->toHaveCount(1)
        ->and($top->first()->replies)->toHaveCount(1);
});

it('lets the author edit their own comment', function () {
    $comment = $this->task->comments()->create(['user_id' => $this->member->id, 'body' => 'Original']);

    Livewire::actingAs($this->member)
        ->test(CommentList::class, ['commentable' => $this->task])
        ->call('startEdit', $comment->id)
        ->set('editBody', 'Edited text')
        ->call('updateComment')
        ->assertHasNoErrors();

    expect($comment->fresh()->body)->toBe('Edited text');
});

it('forbids editing another users comment', function () {
    $other = User::factory()->create();
    joinProject($this->project, $other);
    $comment = $this->task->comments()->create(['user_id' => $other->id, 'body' => 'Not mine']);

    Livewire::actingAs($this->member)
        ->test(CommentList::class, ['commentable' => $this->task])
        ->call('startEdit', $comment->id)
        ->assertForbidden();

    expect($comment->fresh()->body)->toBe('Not mine');
});

it('fully deletes a comment that has no replies', function () {
    $comment = $this->task->comments()->create(['user_id' => $this->member->id, 'body' => 'Bye']);

    Livewire::actingAs($this->member)
        ->test(CommentList::class, ['commentable' => $this->task])
        ->call('confirmDelete', $comment->id)
        ->call('deleteComment');

    expect(Comment::find($comment->id))->toBeNull();
});

it('tombstones a comment with replies, keeping the author and storing the reason', function () {
    $root = $this->task->comments()->create(['user_id' => $this->member->id, 'body' => 'Root']);
    $this->task->comments()->create(['user_id' => $this->member->id, 'body' => 'Reply', 'parent_id' => $root->id]);

    Livewire::actingAs($this->member)
        ->test(CommentList::class, ['commentable' => $this->task])
        ->call('confirmDelete', $root->id)
        ->set('deleteReason', 'Off topic')
        ->call('deleteComment');

    $root->refresh();

    expect($root->is_deleted)->toBeTrue()
        ->and($root->body)->toBe('')
        ->and($root->delete_reason)->toBe('Off topic')
        ->and($root->user_id)->toBe($this->member->id)
        ->and($root->replies()->count())->toBe(1);
});

it('forbids deleting another users comment', function () {
    $other = User::factory()->create();
    joinProject($this->project, $other);
    $comment = $this->task->comments()->create(['user_id' => $other->id, 'body' => 'Theirs']);

    Livewire::actingAs($this->member)
        ->test(CommentList::class, ['commentable' => $this->task])
        ->call('confirmDelete', $comment->id)
        ->assertForbidden();

    expect(Comment::find($comment->id))->not->toBeNull();
});

it('forbids non-members from commenting', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(CommentList::class, ['commentable' => $this->task])
        ->assertForbidden();
});

it('expands the comments section by default', function () {
    Livewire::actingAs($this->member)
        ->test(CommentList::class, ['commentable' => $this->task])
        ->assertSet('collapsed', false);
});

it('persists the collapsed state as a user preference when toggled', function () {
    Livewire::actingAs($this->member)
        ->test(CommentList::class, ['commentable' => $this->task])
        ->call('toggleCollapsed')
        ->assertSet('collapsed', true)
        ->call('toggleCollapsed')
        ->assertSet('collapsed', false);

    expect($this->member->fresh()->preference('comments_collapsed'))->toBeFalse();
});

it('restores the collapsed state from the user preference on mount', function () {
    $this->member->setPreference('comments_collapsed', true);

    Livewire::actingAs($this->member)
        ->test(CommentList::class, ['commentable' => $this->task])
        ->assertSet('collapsed', true);
});

it('applies the collapsed preference across all commentable types', function () {
    Livewire::actingAs($this->member)
        ->test(CommentList::class, ['commentable' => $this->task])
        ->call('toggleCollapsed')
        ->assertSet('collapsed', true);

    Livewire::actingAs($this->member->fresh())
        ->test(CommentList::class, ['commentable' => $this->project])
        ->assertSet('collapsed', true);

    Livewire::actingAs($this->member->fresh())
        ->test(CommentList::class, ['commentable' => $this->subtask])
        ->assertSet('collapsed', true);
});
