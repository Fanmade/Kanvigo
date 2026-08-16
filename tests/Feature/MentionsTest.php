<?php

use App\Livewire\Comments\CommentList;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\UserMentioned;
use App\Support\MentionLinker;
use App\Support\MentionParser;
use App\Support\RichTextSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function mentionSpan(int $userId, string $label = '@Name'): string
{
    return '<span class="mention" data-type="mention" data-id="'.$userId.'">'.$label.'</span>';
}

it('parses distinct mention user ids from rich-text html', function () {
    $html = '<p>Hi '.mentionSpan(7).' and '.mentionSpan(7).' and '.mentionSpan(9).'</p>';

    expect(MentionParser::userIds($html))->toBe([7, 9]);
});

it('returns no mention ids for plain content', function () {
    expect(MentionParser::userIds('<p>just text, no mentions</p>'))->toBe([])
        ->and(MentionParser::userIds(''))->toBe([]);
});

it('keeps mention and reference nodes through sanitization', function () {
    $html = '<p>'.mentionSpan(3, '@Alice')
        .' see <a class="reference" data-type="reference" data-id="4" href="/KAN-4">KAN-4</a></p>';

    $out = app(RichTextSanitizer::class)->sanitize($html);

    expect($out)
        ->toContain('data-type="mention"')
        ->toContain('data-id="3"')
        ->toContain('data-type="reference"')
        ->toContain('href="/KAN-4"');
});

it('indexes mentions from a task description, limited to project members', function () {
    $member = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, $member);
    $outsider = User::factory()->create();

    $task = Task::factory()->for($project)->create([
        'description' => '<p>'.mentionSpan($member->id).' and '.mentionSpan($outsider->id).'</p>',
    ]);

    // The outsider is not a project member, so their mention is dropped.
    expect($task->mentions()->pluck('users.id')->all())->toBe([$member->id]);
});

it('reconciles the mention index as the content changes', function () {
    $member = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, $member);

    $task = Task::factory()->for($project)->create([
        'description' => '<p>'.mentionSpan($member->id).'</p>',
    ]);
    expect($task->mentions()->pluck('users.id')->all())->toBe([$member->id]);

    // Removing the mention detaches it.
    $task->update(['description' => '<p>no more mentions</p>']);
    expect($task->fresh()->mentions()->pluck('users.id')->all())->toBe([]);

    // Adding it back re-attaches it.
    $task->update(['description' => '<p>'.mentionSpan($member->id).' again</p>']);
    expect($task->fresh()->mentions()->pluck('users.id')->all())->toBe([$member->id]);
});

it('indexes mentions on a comment from the surrounding item members', function () {
    $author = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, $author);
    joinProject($project, $member);
    $task = Task::factory()->for($project)->create();

    $comment = $task->comments()->create([
        'user_id' => $author->id,
        'body' => '<p>ping '.mentionSpan($member->id).'</p>',
    ]);

    expect($comment->mentions()->pluck('users.id')->all())->toBe([$member->id]);
});

it('notifies and auto-subscribes a member mentioned in a task description', function () {
    Notification::fake();

    $author = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, $author);
    joinProject($project, $member);

    $this->actingAs($author);
    $task = Task::factory()->for($project)->create([
        'description' => '<p>'.mentionSpan($member->id).'</p>',
    ]);

    Notification::assertSentTo($member, UserMentioned::class);
    expect($task->subscribers()->whereKey($member->id)->exists())->toBeTrue();
});

it('does not notify you for mentioning yourself', function () {
    Notification::fake();

    $member = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, $member);

    $this->actingAs($member);
    Task::factory()->for($project)->create([
        'description' => '<p>'.mentionSpan($member->id).'</p>',
    ]);

    Notification::assertNothingSentTo($member);
});

it('notifies a member mentioned in a comment and subscribes them to the item', function () {
    Notification::fake();

    $author = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, $author);
    joinProject($project, $member);
    $task = Task::factory()->for($project)->create();

    $this->actingAs($author);
    $task->comments()->create([
        'user_id' => $author->id,
        'body' => '<p>ping '.mentionSpan($member->id).'</p>',
    ]);

    Notification::assertSentTo($member, UserMentioned::class);
    expect($task->subscribers()->whereKey($member->id)->exists())->toBeTrue();
});

it('notifies a member mentioned in a doc body and links the notification to the doc', function () {
    Notification::fake();

    $author = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $author);
    joinProject($project, $member);

    $this->actingAs($author);
    $doc = Doc::factory()->for($project)->create([
        'body' => '<p>'.mentionSpan($member->id).'</p>',
    ]);

    expect($doc->mentions()->whereKey($member->id)->exists())->toBeTrue();

    Notification::assertSentTo($member, UserMentioned::class, function (UserMentioned $notification) use ($doc, $member): bool {
        $payload = $notification->toArray($member);

        return $payload['url'] === route('doc.show', ['short_name' => 'ABC', 'doc_number' => $doc->doc_number])
            && $payload['reference'] === $doc->reference;
    });
});

it('drops a doc mention of someone outside the project', function () {
    $stranger = User::factory()->create();
    $doc = Doc::factory()->create([
        'body' => '<p>'.mentionSpan($stranger->id).'</p>',
    ]);

    expect($doc->mentions()->count())->toBe(0);
});

it('keeps a mention link working after the mentioned user is renamed', function () {
    $member = User::factory()->create(['name' => 'Ada Lovelace']);
    $project = Project::factory()->create();
    joinProject($project, $member);

    $comment = Task::factory()->for($project)->create()->comments()->create([
        'user_id' => $member->id,
        'body' => '<p>ping '.mentionSpan($member->id, '@Ada Lovelace').'</p>',
    ]);

    $member->update(['name' => 'Ada Byron']);

    // The mention stores the id, so the link still resolves to the same person.
    expect($comment->fresh()->mentions()->pluck('users.id')->all())->toBe([$member->id])
        ->and(MentionLinker::link($comment->body))
        ->toContain('href="'.route('users.show', $member->fresh()->public_id).'"');
});

it('does not notify a comment mention of someone outside the project', function () {
    Notification::fake();

    $author = User::factory()->create();
    $outsider = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, $author);
    $task = Task::factory()->for($project)->create();

    $this->actingAs($author);
    $comment = $task->comments()->create([
        'user_id' => $author->id,
        'body' => '<p>ping '.mentionSpan($outsider->id).'</p>',
    ]);

    expect($comment->mentions()->count())->toBe(0);
    Notification::assertNothingSentTo($outsider);
});

it('notifies a member mentioned through the comment composer', function () {
    Notification::fake();

    $author = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, $author);
    joinProject($project, $member);
    $task = Task::factory()->for($project)->create();

    Livewire::actingAs($author)
        ->test(CommentList::class, ['commentable' => $task])
        ->set('body', '<p>ping '.mentionSpan($member->id).'</p>')
        ->call('addComment')
        ->assertHasNoErrors();

    Notification::assertSentTo($member, UserMentioned::class);
});

it('does not resubscribe someone who unsubscribed when mentioning them', function () {
    Notification::fake();

    $author = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, $author);
    joinProject($project, $member);
    $task = Task::factory()->for($project)->create();
    $task->autoSubscribe([$member->id]);
    $task->unsubscribe($member);

    $this->actingAs($author);
    $task->comments()->create([
        'user_id' => $author->id,
        'body' => '<p>ping '.mentionSpan($member->id).'</p>',
    ]);

    // The mention itself still reaches them — it is addressed at them — but it
    // does not sign them back up for everything else that happens here.
    Notification::assertSentTo($member, UserMentioned::class);
    expect($task->isSubscribedBy($member))->toBeFalse();
});

it('only notifies newly-mentioned users, not on every save', function () {
    Notification::fake();

    $author = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, $author);
    joinProject($project, $member);

    $this->actingAs($author);
    $task = Task::factory()->for($project)->create([
        'description' => '<p>'.mentionSpan($member->id).'</p>',
    ]);
    Notification::assertSentToTimes($member, UserMentioned::class, 1);

    // Editing the description but keeping the same mention does not re-notify.
    $task->update(['description' => '<p>'.mentionSpan($member->id).' with more text</p>']);
    Notification::assertSentToTimes($member, UserMentioned::class, 1);
});
