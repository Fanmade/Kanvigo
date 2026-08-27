<?php

use App\Models\Project;
use App\Models\User;

/**
 * The banner is a direct child of <body>, next to Flux's sidebar and header.
 * Rendering the component on its own — which is all the feature test does — says
 * nothing about where the layout actually puts it, so this drives the real flow
 * in a browser (KAN-579).
 */
it('shows the impersonation banner across the content area', function () {
    // The admin area itself needs `manage-users`; impersonating needs its own
    // permission on top.
    $administrator = User::factory()->canManageUsers()->canImpersonateUsers()->create();
    $target = User::factory()->create(['name' => 'Bastion Target']);
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, [$administrator->id, $target->id]);

    $this->actingAs($administrator);

    $page = visit('/admin/users');
    $page->click('@impersonate-'.$target->id)
        ->assertVisible('@impersonation-banner')
        ->assertVisible('@stop-impersonating-banner')
        ->assertSeeIn('@impersonation-banner', 'Bastion Target');

    // The banner must occupy the content column, not a sliver of an implicit
    // grid track: anything under a couple of hundred pixels is the bug.
    $width = $page->script(<<<'JS'
        (() => Math.round(document.querySelector('[data-test=impersonation-banner]')?.getBoundingClientRect().width ?? 0))()
    JS);

    expect(is_array($width) ? $width[0] : $width)->toBeGreaterThan(400);

    // Flux pins a callout's actions with `self-start`; the banner overrides it,
    // so the button's centre must line up with the text's.
    $offset = $page->script(<<<'JS'
        (() => {
            const banner = document.querySelector('[data-test=impersonation-banner]');
            const text = banner.querySelector('[data-slot=text]').getBoundingClientRect();
            const button = banner.querySelector('[data-test=stop-impersonating-banner]').getBoundingClientRect();

            return Math.round(Math.abs((text.top + text.height / 2) - (button.top + button.height / 2)));
        })()
    JS);

    expect(is_array($offset) ? $offset[0] : $offset)->toBeLessThanOrEqual(2);

    $page->assertNoJavascriptErrors();
});
