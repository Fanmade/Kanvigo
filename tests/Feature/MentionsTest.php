<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\UserMentioned;
use App\Support\MentionParser;
use App\Support\RichTextSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

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
