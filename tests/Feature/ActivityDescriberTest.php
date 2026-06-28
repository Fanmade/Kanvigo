<?php

use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Activity;
use App\Support\ActivityDescriber;

/**
 * Build an unsaved Activity carrying just the fields {@see ActivityDescriber}
 * reads, so these stay pure unit tests with no database.
 */
function describeActivity(string $action, ?string $old = null, ?string $new = null): string
{
    $activity = new Activity;
    $activity->action = $action;
    $activity->old_value = $old;
    $activity->new_value = $new;

    return ActivityDescriber::describe($activity);
}

it('describes simple lifecycle actions', function () {
    expect(describeActivity('created'))->toBe('created this')
        ->and(describeActivity('reopened'))->toBe('reopened this')
        ->and(describeActivity('archived'))->toBe('archived this')
        ->and(describeActivity('unarchived'))->toBe('restored this from the archive')
        ->and(describeActivity('commented'))->toBe('added a comment');
});

it('describes a comment deletion, with and without a reason', function () {
    expect(describeActivity('comment_deleted'))->toBe('deleted a comment')
        ->and(describeActivity('comment_deleted', null, 'wrong task'))->toBe('deleted a comment: wrong task');
});

it('describes a status change with labels', function () {
    expect(describeActivity('status_changed', Status::ToDo->value, Status::Done->value))
        ->toBe('changed status from '.Status::ToDo->label().' to '.Status::Done->label());
});

it('describes a priority change with labels', function () {
    expect(describeActivity('priority_changed', (string) Priority::Low->value, (string) Priority::High->value))
        ->toBe('changed priority from '.Priority::Low->label().' to '.Priority::High->label());
});

it('describes a type change in all three shapes', function () {
    expect(describeActivity('type_changed', 'Bug', 'Chore'))->toBe('changed type from Bug to Chore')
        ->and(describeActivity('type_changed', null, 'Bug'))->toBe('set the type to Bug')
        ->and(describeActivity('type_changed', 'Bug', null))->toBe('cleared the type');
});

it('describes a re-parent move in all three shapes', function () {
    expect(describeActivity('parent_changed', 'KAN-1', 'KAN-2'))->toBe('moved this from KAN-1 to KAN-2')
        ->and(describeActivity('parent_changed', null, 'KAN-2'))->toBe('moved this under KAN-2')
        ->and(describeActivity('parent_changed', 'KAN-1', null))->toBe('moved this to the top level');
});

it('describes assignee changes from name snapshots', function () {
    $added = json_encode(['Bob']);
    $removed = json_encode(['Alice']);

    expect(describeActivity('assignee_changed', $removed, $added))->toBe('assigned Bob, unassigned Alice')
        ->and(describeActivity('assignee_changed', null, $added))->toBe('assigned Bob')
        ->and(describeActivity('assignee_changed', $removed, null))->toBe('unassigned Alice');
});

it('describes tag changes from name snapshots', function () {
    $added = json_encode(['urgent']);
    $removed = json_encode(['stale']);

    expect(describeActivity('tags_changed', $removed, $added))->toBe('added the tags urgent, removed stale')
        ->and(describeActivity('tags_changed', null, $added))->toBe('added the tags urgent')
        ->and(describeActivity('tags_changed', $removed, null))->toBe('removed the tags stale');
});

it('describes tag lifecycle actions', function () {
    expect(describeActivity('tag_renamed', 'old', 'new'))->toBe('renamed the tag old to new')
        ->and(describeActivity('tag_recolored', null, json_encode(['name' => 'urgent'])))->toBe('changed the color of the tag urgent')
        ->and(describeActivity('tag_deleted', 'stale'))->toBe('deleted the tag stale')
        ->and(describeActivity('tag_merged', 'a', 'b'))->toBe('merged the tag a into b');
});

it('describes a cancellation with reason and message', function () {
    $payload = json_encode(['reason' => 'duplicate', 'message' => 'see KAN-1']);

    expect(describeActivity('canceled', null, $payload))->toContain('see KAN-1');
});

it('falls back to the raw action for unknown verbs', function () {
    expect(describeActivity('teleported'))->toBe('teleported');
});
